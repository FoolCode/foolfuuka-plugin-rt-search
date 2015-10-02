<?php

namespace Foolz\FoolFuuka\Plugins\RtSearch\Console;

use Foolz\FoolFrame\Model\Context;
use Foolz\FoolFrame\Model\DoctrineConnection;
use Foolz\FoolFrame\Model\Preferences;
use Foolz\FoolFuuka\Model\Radix;
use Foolz\FoolFuuka\Model\RadixCollection;
use Foolz\FoolFuuka\Plugins\RtSearch\Model\RtSearch as RT;
use Foolz\SphinxQL\Drivers\Mysqli\Connection;
use Foolz\SphinxQL\SphinxQL;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Console extends Command
{
    /**
     * @var \Foolz\FoolFrame\Model\Context
     */
    protected $context;

    /**
     * @var Preferences
     */
    protected $preferences;

    /**
     * @var DoctrineConnection
     */
    protected $dc;

    /**
     * @var Connection
     */
    protected $sphinx_conn;

    /**
     * @var
     */
    protected $rt_search;

    /**
     * @var RadixCollection
     */
    protected $radix_coll;

    public function __construct(Context $context)
    {
        $this->context = $context;
        $this->preferences = $context->getService('preferences');
        $this->dc = $context->getService('doctrine');
        $this->radix_coll = $context->getService('foolfuuka.radix_collection');
        $this->rt_search = $context->getService('foolfuuka-plugin.rt_search');
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('rt_search:run')
            ->setDescription('Updates the RT search index in an endless loop')
            ->addOption(
                'radix',
                null,
                InputOption::VALUE_OPTIONAL,
                _i('Run the queries only for the requested board')
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (($radix = $input->getOption('radix')) !== null) {
            if ($this->radix_coll->getByShortname($radix) !== false) {
                $this->rtSearch($output, $radix);
            } else {
                $output->writeln('<error>'._i('Wrong radix (board short name) specified.').'</error>');
            }
        } else {
            $this->rtSearch($output);
        }
    }

    public function rtSearch($output, $shortname = null)
    {
        $boards = $this->radix_coll->getAll();

        while (true) {
            foreach ($boards as $board) {
                if (!is_null($shortname) && $shortname != $board->shortname) {
                    continue;
                }

                $this->rtSearch($output, $board);
                sleep(1);
            }
        }
    }

    protected function getSphinxql()
    {
        if ($this->sphinx_conn === null) {
            $sphinx_config = explode(':', $this->preferences->get('foolfuuka.sphinx.listen'));
            $this->sphinx_conn = new Connection();
            $this->sphinx_conn->setParams([
                'host' => $sphinx_config[0],
                'port' => $sphinx_config[1],
                'options' => [MYSQLI_OPT_CONNECT_TIMEOUT => 5]
            ]);
        }

        return new SphinxQL($this->sphinx_conn);
    }

    /**
     * @param $output
     * @param Radix $board
     */
    public function rt_search_per_board($output, $board)
    {
        //$preferences_key = 'foolfuuka.plugins.rt_search.board.'.$board->shortname.'.latest_doc_id';
        $latest_doc_id = $this->getLatestDocIdFromSphinx();// $this->preferences->get($preferences_key, );

        $res = $this->dc->qb()
            ->select('*')
            ->from($board->getTable(), 'r')
            ->leftJoin('r', $board->getTable('_images'), 'mg', 'mg.media_id = r.media_id')
            ->andWhere('doc_id > :latest_doc_id')
            ->orderBy('doc_id', 'ASC')
            ->setMaxResults(25000)
            ->setParameter(':latest_doc_id', $latest_doc_id)
            ->execute()
            ->fetchAll();

        $sphinxql = $this->getSphinxql();
        //$last_doc_id = 0;
        foreach ($res as $row) {
            $sphinxql->insert()
                ->into($board->shortname.'_rt')
                ->values([]);

            $sphinxql = $sphinxql->enqueue();
            //$last_doc_id = $row->doc_id;
        }

        $sphinxql->executeBatch();
        //$this->preferences->set($preferences_key, $last_doc_id);
    }

    protected function getLatestDocIdFromSphinx($board) {
        $res = $this->getSphinxql()
            ->select(SphinxQL::expr('MAX(doc_id)'))
            ->from($board->shortname.'_rt', $board->shortname.'_main', $board->shortname.'_ancient')
            ->execute()
            ->fetchAssoc();

        return $res['MAX(doc_id)'];
    }

}
