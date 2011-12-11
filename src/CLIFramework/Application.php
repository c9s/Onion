<?php
/*
 * This file is part of the {{ }} package.
 *
 * (c) Yo-An Lin <cornelius.howl@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */
namespace CLIFramework;

use GetOptionKit\ContinuousOptionParser;
use GetOptionKit\OptionSpecCollection;

use CLIFramework\CommandDispatcher;
use CLIFramework\CommandLoader;
use CLIFramework\CommandBase;

use Exception;

class Application extends CommandBase
{

    // command class loader
    public $loader;

    // options parser
    public $optionParser;

    // command namespace for autoloader
    public $commandNamespaces = array( 
        // '\\Onion\\Command',
        '\\CLIFramework\\Command'
    );


    function __construct()
    {
        // get current class namespace, add {App}\Command\ to loader
        $app_ref_class = new \ReflectionClass($this);
        $app_ns = $app_ref_class->getNamespaceName();

        $this->loader = new CommandLoader();
        $this->loader->addNamespace( $app_ns . '\\Command' );
        $this->loader->addNamespace( $this->commandNamespaces );


        $this->optionsParser = new ContinuousOptionParser;
    }


    /**
     * register application option specs to the parser
     */
    public function options($getopt)
    {
        // $parser->add( );

    }


    /* 
     * init application,
     *
     * users register command mapping here. (command to class name)
     */
    public function init()
    {
        $this->registerCommand('list','\CLIFramework\Command\ListCommand');
        $this->registerCommand('help','\CLIFramework\Command\HelpCommand');
    }


    /**
     * run application with 
     * list argv 
     *
     * @param Array $argv
     *
     * */
    public function run(Array $argv)
    {
        // init application 
        $this->init();

        // use getoption kit to parse application options
        $getopt = $this->optionsParser;

        $specs = new OptionSpecCollection;

        // init application options
        $this->options($specs);
        $getopt->setOptions( $specs );
        $this->options = $getopt->parse( $argv );

        $command_stack = array();
        $subcommand_list = $this->getCommandList();

        $arguments = array();
        $cmd = null;

        while( ! $getopt->isEnd() ) {

            if( in_array(  $getopt->getCurrentArgument() , $subcommand_list ) ) {
                $getopt->advance();
                $subcommand = array_shift( $subcommand_list );

                // initialize subcommand (subcommand with parent command class)
                $command_class = null;
                if( end($command_stack) ) {
                    $command_class = $this->loader->loadSubcommand($subcommand, end($command_stack));
                } 
                else {
                    $command_class = $this->loader->load( $subcommand );
                }

                if( ! $command_class ) {
                    throw new Exception("command $subcommand not found.");
                }

                $cmd = new $command_class;

                // init subcommand option
                $command_specs = new OptionSpecCollection;
                $getopt->setOptions($command_specs);
                $cmd->options( $command_specs );

                // register subcommands
                $cmd->init();

                // parse options for command.
                $cmd_options = $getopt->continueParse();

                // run subcommand prepare
                $cmd->prepare();

                $cmd->options = $cmd_options;
                $command_stack[] = $cmd; // save command object into the stack

                // update subcommand list
                $subcommand_list = $cmd->getCommandList();

            } else {
                $arguments[] = $getopt->advance();
            }
        }

        // get last command and run
        if( $last_cmd = array_pop( $subcommand_list ) ) {
            $last_cmd->execute( $arguments );
            while( $cmd = array_pop( $subcommand_list ) ) {
                // call finish stage.. of every command.
                $cmd->finish();
            }
        }
        else {
            // no command specified.
            $this->execute( $arguments );
        }
    }


    public function execute( $arguments = array() )
    {
        // show list and help by default
        $help_class = $this->getCommandClass( 'help' );
        $help = new $help_class;
        $help->execute($arguments);
    }

}
