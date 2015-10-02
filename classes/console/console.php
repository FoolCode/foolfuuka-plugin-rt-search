<?php

namespace Foolz\FoolFuuka\Plugins\RtSearch\Console;

use Foolz\FoolFrame\Model\Context;
use Foolz\FoolFrame\Model\DoctrineConnection;
use Foolz\FoolFrame\Model\Preferences;
use Foolz\FoolFuuka\Model\Radix;
use Foolz\FoolFuuka\Model\RadixCollection;
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
     * @var RadixCollection
     */
    protected $radix_coll;

    public function __construct(Context $context)
    {
        $this->context = $context;
        $this->preferences = $context->getService('preferences');
        $this->dc = $context->getService('doctrine');
        $this->radix_coll = $context->getService('foolfuuka.radix_collection');
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

                $this->rtSearchPerBoard($output, $board);
                sleep(1);
            }
        }
    }

    protected function getSphinxql()
    {
        if (!$this->sphinx_conn) {
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
    public function rtSearchPerBoard($output, $board)
    {
        $latest_doc_id = $this->getLatestDocIdFromSphinx($board);

        $res = $this->dc->qb()
            ->select('*')
            ->from($board->getTable(), 'r')
            ->andWhere('doc_id > :latest_doc_id')
            ->orderBy('doc_id', 'ASC')
            ->setMaxResults(25000)
            ->setParameter(':latest_doc_id', $latest_doc_id)
            ->execute()
            ->fetchAll();

        $sphinxql = $this->getSphinxql();
        foreach ($res as $row) {
            $sphinxql->insert()
                ->into($board->shortname.'_delta')
                ->set([
                    'num' => $row->num,
                    'subnum' => $row->subnum,
                    'tnum' => $row->thread_num,
                    'cap' => $row->capcode,
                    'board' => $board->id,
                    'mid' => $row->media_id,
                    'pip' => $row->poster_ip,
                    'has_image' => !!$row->media_filename,
                    'is_internal' => !!$row->subnum,
                    'is_spoiler' => $row->spoiler,
                    'is_deleted' => $row->deleted,
                    'is_sticky' => $row->sticky,
                    'is_op' => $row->op,
                    'timestamp' => $row->timestamp,

                    'trip' => $row->trip,
                    'email' => $row->email,
                    'title' => $row->title,
                    'comment' => $row->comment,
                    'media_filename' => $row->media_filename,
                    'media_hash' => $row->media_hash,
                    'country' => $row->poster_country
                ]);

            $sphinxql = $sphinxql->enqueue();
        }

        $sphinxql->executeBatch();
    }

    protected function getLatestDocIdFromSphinx($board) {
        $res = $this->getSphinxql()
            ->select(SphinxQL::expr('MAX(doc_id)'))
            ->from($board->shortname.'_delta', $board->shortname.'_main', $board->shortname.'_ancient')
            ->execute()
            ->fetchAssoc();

        return $res['MAX(doc_id)'];
    }
}
