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
                $this->rtSearch($input, $output, $radix);
            } else {
                $output->writeln('<error>'._i('Wrong radix (board short name) specified.').'</error>');
            }
        } else {
            $this->rtSearch($input, $output);
        }
    }

    public function rtSearch($input, $output, $shortname = null)
    {
        $boards = $this->radix_coll->getAll();

        $cron = true;
        while (true === $cron) {
            foreach ($boards as $board) {
                if (!is_null($shortname) && $shortname != $board->shortname) {
                    continue;
                }

                if ($input->getOption('purge')) {
                    $this->rtPurgePerBoard($output, $board);
                    $cron = false;
                } else {
                    $this->rtSearchPerBoard($output, $board);
                    sleep(1);
                }
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

    public function rtPurgePerBoard($output, $board)
    {
        $output->writeln(date("Y-m-d H:i:s").' - Purging /'.$board->shortname.'/...');
        $this->getSphinxql()->query('TRUNCATE RTINDEX '.$board->shortname.'_delta')->execute();
    }

    /**
     * @param $output
     * @param Radix $board
     */
    public function rtSearchPerBoard($output, $board)
    {
        $output->writeln(date("Y-m-d H:i:s").' - Indexing /'.$board->shortname.'/...');
        $latest_doc_id = $this->getLatestDocIdFromSphinx($board);

        $res = $this->dc->qb()
            ->select('*')
            ->from($board->getTable(), 'r')
            ->where('doc_id > :latest_doc_id')
            ->orderBy('doc_id', 'ASC')
            ->setMaxResults(5000)
            ->setParameter(':latest_doc_id', $latest_doc_id)
            ->execute()
            ->fetchAll();

        $sphinxql = $this->getSphinxql();
        foreach ($res as $row) {
            $sphinxql->insert()
                ->into($board->shortname.'_delta')
                ->set(array_filter([
                    'id' => $row['doc_id'],
                    'num' => $row['num'],
                    'subnum' => $row['subnum'],
                    'tnum' => $row['thread_num'],
                    'cap' => ord($row['capcode']),
                    'board' => $board->id,
                    'mid' => $row['media_id'],
                    'pip' => $row['poster_ip'],
                    'has_image' => !!$row['media_filename'],
                    'is_internal' => !!$row['subnum'],
                    'is_spoiler' => $row['spoiler'],
                    'is_deleted' => $row['deleted'],
                    'is_sticky' => $row['sticky'],
                    'is_locked' => $row['locked'],
                    'is_op' => $row['op'],
                    'timestamp' => $row['timestamp'],

                    'name' => $row['name'],
                    'trip' => $row['trip'],
                    'email' => $row['email'],
                    'title' => $row['title'],
                    'comment' => $row['comment'],
                    'media_filename' => $row['media_filename'],
                    'media_orig' => $row['media_orig'],
                    'media_hash' => $row['media_hash'],
                    'country' => $row['poster_country']
                ], function ($x) { return !is_null($x); }));

            $sphinxql->execute();
        }
    }


    protected function getLatestDocIdFromSphinx($board) {
        $res = $this->getSphinxql()
            ->select(SphinxQL::expr('MAX(id)'))
            ->from($board->shortname.'_delta', $board->shortname.'_main', $board->shortname.'_ancient')
            ->execute()[0];

        return $res['max(id)'];
    }
}
