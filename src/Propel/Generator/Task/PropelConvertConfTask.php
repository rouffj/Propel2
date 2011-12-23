<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Propel\Generator\Task;

use Propel\Generator\Model\Om\ClassTools;
use Propel\Generator\Model\Om\OMBuilder;

require_once 'phing/Task.php';
use \Task;
use \PhingFile;
use \Project;

/**
 * This Task converts the XML runtime configuration file into a PHP array for faster performance.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 */
class PropelConvertConfTask extends AbstractPropelDataModelTask
{

    /**
     * @var        PhingFile The XML runtime configuration file to be converted.
     */
    private $xmlConfFile;

    /**
     * @var        string This is the file where the converted conf array dump will be placed.
     */
    private $outputFile;

    /**
     * @var        string This is the file where the classmap manifest converted conf array dump will be placed.
     */
    private $outputClassmapFile;

    /**
     * [REQUIRED] Set the input XML runtime conf file.
     * @param      PhingFile $v The XML runtime configuration file to be converted.
     */
    public function setXmlConfFile(PhingFile $v)
    {
        $this->xmlConfFile = $v;
    }

    /**
     * [REQUIRED] Set the output filename for the converted runtime conf.
     * The directory is specified using AbstractPropelDataModelTask#setOutputDirectory().
     * @param      string $outputFile
     * @see        AbstractPropelDataModelTask#setOutputDirectory()
     */
    public function setOutputFile($outputFile)
    {
        // this is a string, not a file
        $this->outputFile = $outputFile;
    }

    /**
     * [REQUIRED] Set the output filename for the autoload classmap.
     * The directory is specified using AbstractPropelDataModelTask#setOutputDirectory().
     * @param      string $outputFile
     * @see        AbstractPropelDataModelTask#setOutputDirectory()
     */
    public function setOutputClassmapFile($outputFile)
    {
        // this is a string, not a file
        $this->outputClassmapFile = $outputFile;
    }

    /**
     * The main method does the work of the task.
     */
    public function main()
    {
        // Check to make sure the input and output files were specified and that the input file exists.

        if (!$this->xmlConfFile || !$this->xmlConfFile->exists()) {
            throw new BuildException("No valid xmlConfFile specified.", $this->getLocation());
        }

        if (!$this->outputFile) {
            throw new BuildException("No outputFile specified.", $this->getLocation());
        }

        $this->log('Loading XML configuration file...');

        // Create a PHP array from the runtime-conf.xml file
        $xmlDom = new \DOMDocument();
        $xmlDom->load($this->xmlConfFile->getAbsolutePath());
        $xml = simplexml_load_string($xmlDom->saveXML());
        $phpconf = self::simpleXmlToArray($xml);
        $this->log(sprintf('Loaded "%s" successfully', $this->xmlConfFile->getAbsolutePath()), Project::MSG_VERBOSE);

        /* For some reason the array generated from runtime-conf.xml has separate
         * 'log' section and 'propel' sections. To maintain backward compatibility
         * we need to put 'log' back into the 'propel' section.
         */
        $log = array();
        if (isset($phpconf['log'])) {
            $phpconf['propel']['log'] = $phpconf['log'];
            unset($phpconf['log']);
        }
        if (isset($phpconf['profiler'])) {
            $phpconf['propel']['profiler'] = $phpconf['profiler'];
            unset($phpconf['profiler']);
        }

        if (isset($phpconf['propel'])) {
            $phpconf = $phpconf['propel'];
        }

        // add generator version
        $phpconf['generator_version'] = $this->getGeneratorConfig()->getBuildProperty('version');

        if (!$this->outputClassmapFile) {
            // We'll create a default one for BC
            $this->outputClassmapFile = 'classmap-' . $this->outputFile;
        }

        // Write resulting PHP data to output file
        $outfile = new PhingFile($this->outputDirectory, $this->outputFile);
        $output = "<?php\n";
        $output .= "// This file generated by Propel " . $phpconf['generator_version'] . " convert-conf target".($this->getGeneratorConfig()->getBuildProperty('addTimestamp') ? " on " . strftime("%c") : '') . "\n";
        $output .= "// from XML runtime conf file " . $this->xmlConfFile->getPath() . "\n";
        $output .= "\$conf = ";
        $output .= var_export($phpconf, true);
        $output .= ";\n";
        $output .= "\$conf['classmap'] = include(dirname(__FILE__) . DIRECTORY_SEPARATOR . '".$this->outputClassmapFile."');\n";
        $output .= "return \$conf;";

        $mustWriteRuntimeConf = true;
        if (file_exists($outfile->getAbsolutePath())) {
            $currentRuntimeConf = file_get_contents($outfile->getAbsolutePath());
            if ($currentRuntimeConf == $output) {
                $this->log(sprintf('No change in PHP runtime conf file "%s"', $outfile->getPath()), Project::MSG_VERBOSE);
                $mustWriteRuntimeConf = false;
            } else {
                $this->log(sprintf('Updating PHP runtime conf file "%s"', $outfile->getPath()));
            }
        } else {
            $this->log(sprintf('Creating PHP runtime conf file "%s"', $outfile->getPath()));
        }
        if ($mustWriteRuntimeConf && !file_put_contents($outfile->getAbsolutePath(), $output)) {
            throw new BuildException("Error writing output file: " . $outfile->getAbsolutePath(), $this->getLocation());
        }

        // add classmap
        $phpconfClassmap = $this->getClassMap();
        $outfile = new PhingFile($this->outputDirectory, $this->outputClassmapFile);
        $output = '<' . '?' . "php\n";
        $output .= "// This file generated by Propel " . $phpconf['generator_version'] . " convert-conf target".($this->getGeneratorConfig()->getBuildProperty('addTimestamp') ? " on " . strftime("%c") : '') . "\n";
        $output .= "return ";
        $output .= var_export($phpconfClassmap, true);
        $output .= ";";

        $mustWriteClassMap = true;
        if (file_exists($outfile->getAbsolutePath())) {
            $currentClassmap = file_get_contents($outfile->getAbsolutePath());
            if ($currentClassmap == $output) {
                $this->log(sprintf('No change in PHP classmap file "%s"', $outfile->getPath()), Project::MSG_VERBOSE);
                $mustWriteClassMap = false;
            } else {
                $currentPhpconfClassmap = include $outfile->getAbsolutePath();
                if ($addedMaps = array_diff_assoc($phpconfClassmap, $currentPhpconfClassmap)) {
                    $this->log('Added classmap for the following classes:', Project::MSG_VERBOSE);
                    $this->logClassMap($addedMaps);
                }
                if ($removedMaps = array_diff_assoc($currentPhpconfClassmap, $phpconfClassmap)) {
                    $this->log('Removed classmap for the following classes:', Project::MSG_VERBOSE);
                    $this->logClassMap($removedMaps);
                }
                $this->log(sprintf('Updating PHP classmap file "%s"', $outfile->getPath()));
            }
        } else {
            $this->log(sprintf('Creating PHP classmap file "%s"', $outfile->getPath()));
            $this->logClassMap($phpconfClassmap);
        }
        if ($mustWriteClassMap && !file_put_contents($outfile->getAbsolutePath(), $output)) {
            throw new BuildException("Error writing output file: " . $outfile->getAbsolutePath(), $this->getLocation());
        }

        if (!$mustWriteRuntimeConf && !$mustWriteClassMap) {
            $this->log('No change in compiled configuration files');
        }

    } // main()

    protected function logClassMap($classMap)
    {
        foreach ($classMap as $className => $classPath) {
            $this->log(sprintf('  %-15s => %s', $className, $classPath), Project::MSG_VERBOSE);
        }
    }
    /**
     * Recursive function that converts an SimpleXML object into an array.
     * @author     Christophe VG (based on code form php.net manual comment)
     * @param      object SimpleXML object.
     * @return     array Array representation of SimpleXML object.
     */
    protected static function simpleXmlToArray($xml)
    {
        $ar = array();

        foreach ($xml->children() as $k => $v) {

            // recurse the child
            $child = self::simpleXmlToArray( $v );

            //print "Recursed down and found: " . var_export($child, true) . "\n";

            // if it's not an array, then it was empty, thus a value/string
            if ( count($child) == 0 ) {
                $child = self::getConvertedXmlValue($v);

            }

            // add the childs attributes as if they where children
            foreach ($v->attributes() as $ak => $av) {

                if ($ak == 'id') {
                    // special exception: if there is a key named 'id'
                    // then we will name the current key after that id
                    $k = self::getConvertedXmlValue($av);
                } else {
                    // otherwise, just add the attribute like a child element
                    $child[$ak] = self::getConvertedXmlValue($av);
                }
            }

            // if the $k is already in our children list, we need to transform
            // it into an array, else we add it as a value
            if ( !in_array( $k, array_keys($ar) ) ) {
                $ar[$k] = $child;
            } else {
                // (This only applies to nested nodes that do not have an @id attribute)

                // if the $ar[$k] element is not already an array, then we need to make it one.
                // this is a bit of a hack, but here we check to also make sure that if it is an
                // array, that it has numeric keys.  this distinguishes it from simply having other
                // nested element data.

                if (!is_array($ar[$k]) || !isset($ar[$k][0])) {
                    $ar[$k] = array($ar[$k]);
                }
                $ar[$k][] = $child;
            }

        }

        return $ar;
    }

    /**
     * Process XML value, handling boolean, if appropriate.
     * @param      object The simplexml value object.
     * @return     mixed
     */
    private static function getConvertedXmlValue($value)
    {
        $value = (string) $value; // convert from simplexml to string
        // handle booleans specially
        $lwr = strtolower($value);
        if ($lwr === "false") {
            $value = false;
        } elseif ($lwr === "true") {
            $value = true;
        }

        return $value;
    }

    /**
     * Lists data model classes and builds an associative array className => classPath
     * To be used for autoloading
     * @return array
     */
    protected function getClassMap()
    {
        $phpconfClassmap = array();

        $generatorConfig = $this->getGeneratorConfig();

        foreach ($this->getDataModels() as $dataModel) {

            foreach ($dataModel->getDatabases() as $database) {

                $classMap = array();

                foreach ($database->getTables() as $table) {

                    if (!$table->isForReferenceOnly()) {

                        // -----------------------------------------------------
                        // Add TableMap class,
                        //     Peer, Object & Query stub classes,
                        // and Peer, Object & Query base classes
                        // -----------------------------------------------------
                        // (this code is based on PropelOMTask)

                        foreach (array('tablemap', 'peerstub', 'objectstub', 'querystub', 'peer', 'object', 'query') as $target) {
                            $builder = $generatorConfig->getConfiguredBuilder($table, $target);
                            $classMap[$builder->getFullyQualifiedClassname()] = $builder->getClassFilePath();
                        }

                        // -----------------------------------------------------
                        // Add children classes for object and query,
                        // as well as base child query,
                        // for single tabel inheritance tables.
                        // -----------------------------------------------------

                        if ($col = $table->getChildrenColumn()) {
                            if ($col->isEnumeratedClasses()) {
                                foreach ($col->getChildren() as $child) {
                                    foreach (array('objectmultiextend', 'queryinheritance', 'queryinheritancestub') as $target) {
                                        $builder = $generatorConfig->getConfiguredBuilder($table, $target);
                                        $builder->setChild($child);
                                        $classMap[$builder->getFullyQualifiedClassname()] = $builder->getClassFilePath();
                                    }
                                }
                            }
                        }

                        // -----------------------------------------------------
                        // Add base classes for alias tables (undocumented)
                        // -----------------------------------------------------

                        $baseClass = $table->getBaseClass();
                        if ( $baseClass !== null ) {
                            $className = ClassTools::classname($baseClass);
                            if (!isset($classMap[$className])) {
                                $classPath = ClassTools::getFilePath($baseClass);
                                $classMap[$className] = $classPath;
                            }
                        }

                        $basePeer = $table->getBasePeer();
                        if ( $basePeer !== null ) {
                            $className = ClassTools::classname($basePeer);
                            if (!isset($classMap[$className])) {
                                $classPath = ClassTools::getFilePath($basePeer);
                                $classMap[$className] = $classPath;
                            }
                        }

                        // ----------------------------------------------
                        // Add classes for interface
                        // ----------------------------------------------

                        if ($table->getInterface()) {
                            $builder = $generatorConfig->getConfiguredBuilder($table, 'interface');
                            $classMap[$builder->getFullyQualifiedClassname()] = $builder->getClassFilePath();
                        }

                        // ----------------------------------
                        // Add classes added by behaviors
                        // ----------------------------------
                        if ($table->hasAdditionalBuilders()) {
                            foreach ($table->getAdditionalBuilders() as $builderClass) {
                                $builder = new $builderClass($table);
                                $builder->setGeneratorConfig($generatorConfig);
                                $classMap[$builder->getFullyQualifiedClassname()] = $builder->getClassFilePath();
                            }
                        }

                    } // if (!$table->isReferenceOnly())
                }

                $phpconfClassmap = array_merge($phpconfClassmap, $classMap);
            }
        }

        // sort the classmap by class name, to avoid discrepancies between OS
        ksort($phpconfClassmap);

        return $phpconfClassmap;
    }
}
