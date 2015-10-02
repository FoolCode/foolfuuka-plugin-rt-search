<?php

use Foolz\FoolFrame\Model\Autoloader;
use Foolz\FoolFrame\Model\Context;
use Foolz\Plugin\Event;

class HHVM_RT
{
    public function run()
    {
        Event::forge('Foolz\Plugin\Plugin::execute#foolz/foolfuuka-plugin-rt-search')
            ->setCall(function ($result) {
                /* @var Context $context */
                $context = $result->getParam('context');
                /** @var Autoloader $autoloader */
                $autoloader = $context->getService('autoloader');

                $autoloader->addClassMap([
                    'Foolz\FoolFuuka\Plugins\RtSearch\Model\RtSearch' => __DIR__ . '/classes/model/rt_search.php'
                ]);

                Event::forge('Foolz\FoolFrame\Model\Context::handleConsole#obj.app')
                    ->setCall(function ($result) use ($context) {
                        $result->getParam('application')
                            ->add(new \Foolz\FoolFuuka\Plugins\RtSearch\Console\Console($context));
                    });;
            });
    }
}

(new HHVM_RT())->run();
