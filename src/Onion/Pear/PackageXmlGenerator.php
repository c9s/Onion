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
namespace Onion\Pear;

use SimpleXMLElement;
use DOMDocument;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Onion\SpecUtils;
use Onion\LoggableInterface;
use SplFileInfo;

/**
 * Generate package.xml from an package
 *
 *
 *      $pkgxml = new PackageXmlGenerator( $pkginfo );
 *      $pkgxml->setLogger( $logger );
 *      $pkgxml->setUseDefault(true);
 *      $pkgxml->setReformat(true);
 *      $pkgxml->generate('package.xml');
 *
 */
class PackageXmlGenerator implements LoggableInterface
{

    public $package;
    public $reformat = true;
    public $useDefault = true;

    function __construct($package)
    {
        $this->package = $package;
    }

    function setUseDefault($bool)
    {
        $this->useDefault = $bool;
    }

    function setReformat($bool)
    {
        $this->reformat = $bool;
    }

    function setLogger(\CLIFramework\Logger $logger)
    {
        $this->logger = $logger;
    }

    function getLogger()
    {
        return $this->logger;
    }

    function generate()
    {
        $logger = $this->getLogger();
        try {
            $package = $this->package;
            $config = $this->package->config;

            $xmlstr = <<<XML
<package packagerversion="1.4.10" version="2.0"
    xmlns="http://pear.php.net/dtd/package-2.0"
    xmlns:tasks="http://pear.php.net/dtd/tasks-1.0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="http://pear.php.net/dtd/tasks-1.0
              http://pear.php.net/dtd/tasks-1.0.xsd
              http://pear.php.net/dtd/package-2.0
              http://pear.php.net/dtd/package-2.0.xsd">
</package>
XML;

            $xml = new SimpleXMLElement($xmlstr);
            $xml->name = $config->{ 'package.name' };
            $xml->channel = $config->{ 'package.channel' };
            $xml->summary = $config->{ 'package.summary' };
            $xml->description = $config->{ 'package.desc' };

            if ($config->has('package.extends'))
                $xml->extends = $config->get('package.extends');

            $provideExtension = $config->get('package.extension');

            $author_data = SpecUtils::parseAuthor($config->get('package.author'));
            $lead = $xml->addChild('lead');
            foreach ($author_data as $k => $v)
                $lead->$k = $v;
            $lead->active = 'yes';

            if ($config->has('package.authors')) {
                foreach ($config->get('package.authors') as $author) {
                    $lead = $xml->addChild('lead');
                    $data = SpecUtils::parseAuthor($author);
                    foreach ($data as $k => $v)
                        $lead->$k = $v;
                    $lead->active = 'yes';
                }
            }

            $xml->date = date('Y-m-d');
            $xml->time = strftime('%T');

            // add version block
            $version = $xml->addChild('version');
            $version->release = $config->get('package.version');
            $version->api = $config->get('package.version.api');

            // stability block
            $stability = $xml->addChild('stability');
            $stability->release = $config->get('package.stability.release');  # XXX: detect from version number.
            $stability->api = $config->get('package.stability.api');


            // XXX: license, support license url later
            $xml->license = $config->get('package.license');

            $xml->notes = $config->get('package.notes') ? : '-';




            // build content sections
            $logger->info('Building contents section...');

            $roles = $package->getDefaultStructureConfig();
            // default roles
            $filelist = array();
            foreach ($roles as $role => $paths) {
                foreach ($paths as $path) {
                    $logger->debug("treat path \"$path\" as \"$role\" role", 1);
                    $files = $this->addPathByRole($path, $role);
                    $filelist = array_merge($filelist, $files);
                }
            }

            $customRoles = $config->get('roles');
            if ($customRoles) {
                foreach ($customRoles as $pattern => $role) {
                    if ( isset($roles['test']) && in_array($pattern, $roles['test'])) {
                        continue;
                    }
                    $logger->debug("treat \"$pattern\" as \"$role\" role", 1);
                    $files = $this->addPathByRole($pattern, $role);
                    $filelist = array_merge($filelist, $files);
                }
            }

            // filter filelists

            $filelist = array_filter($filelist, function($file) use($provideExtension) {
                $role = $file->role;
                if ( $provideExtension && ! in_array($role, array("src") ) ) {
                    return false;
                } elseif ( ! $provideExtension && ! in_array($role, array("php","script") ) ) {
                    return false;
                }
                return true;
            });

            $contentsXml = $xml->addChild('contents');
            $dir = $contentsXml->addChild('dir');
            $dir->addAttribute('name', '/');
            foreach ($filelist as $contentFile) { // ContentFile class
                $file = $dir->addChild('file');
                $file->addAttribute('name', $contentFile->file);
                $file->addAttribute('role', $contentFile->role);
                $file->addAttribute('md5sum', $contentFile->md5sum);
            }

            // dependencies section
            $logger->info('Building dependencies section...');

            $deps = $xml->addChild('dependencies');
            $required = $deps->addChild('required');

            // build required dependencies
            foreach ($package->deps as $dep) {
                /*
                  <package>
                  <name>GetOptionKit</name>
                  <channel>pear.corneltek.com</channel>
                  <min>0.0.2</min>
                  </package>
                 */

                $logger->debug2(sprintf("dependency %-10s %s", $dep['type'], $dep['name']), 1);

                // only PEAR packages
                switch ($dep['type']) {

                    case 'core':
                        $name = $dep['name'];
                        $depCore = $required->addChild($name);
                        if ($dep['version']) {
                            foreach ($dep['version'] as $k => $v) {
                                $depCore->addChild($k, $v);
                            }
                        }
                        break;

                    case 'pear':
                        $depPackage = $required->addChild('package');
                        $depPackage->addChild('name', $dep['name']);

                        if ($dep['resource']['type'] == 'channel') {
                            $channelHost = $dep['resource']['channel'];
                            $depPackage->addChild('channel', $channelHost);
                        }
                        if ($dep['version']) {
                            foreach ($dep['version'] as $k => $v) {
                                $depPackage->addChild($k, $v);
                            }
                        }
                        break;
                    case 'extension':
                        $depExtension = $required->addChild('extension');
                        $depExtension->addChild('name', $dep['name']);
                        if ($dep['version']) {
                            foreach ($dep['version'] as $k => $v) {
                                $depExtension->addChild($k, $v);
                            }
                        }
                        break;
                }
            }


            // since phprelease can not be used for providesextension
            if ( $provideExtension ) {
                // for <providesextension>extname</providesextension>
                $xml->providesextension = $provideExtension;
                $xml->addChild('extsrcrelease');
            } else {
                // xxx: support optional dependencies
                // xxx: support optional group dependencies
                // phprelease sections
                $logger->info("Building phprelease section..."); {
                    $phprelease = $xml->addChild('phprelease');
                    $filelistNode = $phprelease->addChild('filelist');
                    foreach ($filelist as $contentFile) { // ContentFile class
                        $file = $filelistNode->addChild('install');
                        $file->addAttribute('name', $contentFile->file);
                        $file->addAttribute('as', $contentFile->installAs);
                    }
                }
            }
        } catch (Exception $e) {
            $logger->error($e->getMessage());
            exit(1);
        }


        if (class_exists('DOMDocument')) {
            $logger->info2("* Re-formating XML...", 1);
            $dom = new \DOMDocument('1.0');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            $dom->loadXML($xml->asXML());
            return $dom->saveXML();
        }
        return $xml->asXML();
    }

    function addPathByRole($path, $role)
    {
        $list = array();
        if (is_dir($path)) {
            $baseDir = $path;
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir),
                            RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $path) {
                if ($path->isFile()) {
                    $filepath = $path->getPathname();
                    $list[(string) $path] = $this->buildContentFile($path, $role, $baseDir);
                }
            }
        } else {
            $files = glob($path);
            foreach ($files as $filename) {
                $fileinfo = new SplFileInfo($filename);
                $list[(string) $filename] = $this->buildContentFile($fileinfo, $role);
            }
        }
        return $list;
    }

    public function buildContentFile($fileinfo, $role, $baseDir = '')
    {
        $filepath = $fileinfo->getPathname();

        $contentFile = (object) array();
        $contentFile->file = $filepath;
        $contentFile->role = $role;
        $contentFile->md5sum = md5_file($filepath);

        $contentFile->installAs = basename($filepath);
        if ($baseDir)
            $contentFile->installAs = substr($filepath, strlen($baseDir) + 1);

        $this->logger->debug2(sprintf('%s  %-5s  %s', substr($contentFile->md5sum, 0, 6), $contentFile->role, $contentFile->file), 1);
        return $contentFile;
    }

}
