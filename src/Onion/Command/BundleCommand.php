<?php
/*
 * This file is part of the Onion package.
 *
 * (c) Yo-An Lin <cornelius.howl@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

namespace Onion\Command;
use CLIFramework\Command;
use Onion\Dependency\DependencyResolver;

/**
 * Bundle dependencies
 */
class BundleCommand extends Command
{

    function brief()
    {
        return 'use pear to install dependencies into current .local path';
    }


    /**
     * pecl installer steps
     *
     * wget http://pecl.php.net/get/bcompiler-1.0.2.tgz
     * tar xvf bcompiler-1.0.2.tgz
     * cd bcompiler-1.0.2
     * phpize
     * ./configure
     * make
     * make INSTALL_ROOT=/var/tmp/tmp_root install
     */

    function execute()
    {
		$logger = $this->getLogger();

        // convert package.ini to package.xml
		if( ! file_exists('package.ini') ) {
			$logger->error('package.ini not found, please define one.');
			return false;
		}

        $reader = new \Onion\PackageConfigReader;
        $package = $reader->read( 'package.ini' );
        $package->virtual = 1; // dont install this

        $dr = new DependencyResolver;
        $dr->resolve( $package );

        $pool = $dr->getPool();
        $packages = $pool->getPackages();

        // var_dump( $packages ); 
        foreach( $packages as $package ) {
            // ok( $package );
            // ok( $package->getId() );
            // echo get_class( $package ) . "\n";
            // echo $package->getId() . "\n";
        }
        $installer = new \Onion\Installer( $pool );
        $installer->install();
		$logger->info('Done');
    }
}
