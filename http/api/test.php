<?php

namespace Freegle\Iznik;

use Exception;

function test()
{
    global $dbhr, $dbhm;

    $action = Utils::presdef('action', $_REQUEST, NULL);

    $ret = ['ret' => 100, 'status' => 'Unknown verb'];

    switch ($_REQUEST['type']) {
        case 'GET': {
                if ($action == 'SetupDB') {
                    try {
                        $basePath = '/var/www/iznik/install';

                        // Load and modify schema.sql
                        $schemaPath = "$basePath/schema.sql";
                        if (file_exists($schemaPath)) {
                            $schema = file_get_contents($schemaPath);
                            $schema = str_replace('ROW_FORMAT=DYNAMIC', '', $schema);
                            $schema = str_replace('timestamp(3)', 'timestamp', $schema);
                            $schema = str_replace('timestamp(6)', 'timestamp', $schema);
                            $schema = str_replace('CURRENT_TIMESTAMP(3)', 'CURRENT_TIMESTAMP', $schema);
                            $schema = str_replace('CURRENT_TIMESTAMP(6)', 'CURRENT_TIMESTAMP', $schema);
                            file_put_contents($schemaPath, $schema);
                        } else {
                            throw new Exception("schema.sql not found at $schemaPath");
                        }

                        // Create database if not exists
                        $dbhm->preExec("CREATE DATABASE IF NOT EXISTS iznik");

                        // Load schema.sql
                        $output = [];
                        $returnCode = 0;
                        exec("mysql -h percona -u root -piznik iznik < $basePath/schema.sql 2>&1", $output, $returnCode);
                        if ($returnCode !== 0) {
                            throw new Exception("Failed to load schema.sql: " . implode("\n", $output));
                        }

                        // Load functions.sql
                        exec("mysql -h percona -u root -piznik iznik < $basePath/functions.sql 2>&1", $output, $returnCode);
                        if ($returnCode !== 0) {
                            throw new Exception("Failed to load functions.sql: " . implode("\n", $output));
                        }

                        // Load damlevlim.sql
                        exec("mysql -h percona -u root -piznik iznik < $basePath/damlevlim.sql 2>&1", $output, $returnCode);
                        if ($returnCode !== 0) {
                            throw new Exception("Failed to load damlevlim.sql: " . implode("\n", $output));
                        }

                        // Set SQL mode
                        $dbhm->preExec("SET GLOBAL sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
                        $dbhm->preExec("SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");

                        // Run testenv.php setup
                        $testenvPath = "$basePath/testenv.php";
                        if (file_exists($testenvPath)) {
                            $success = include($testenvPath);
                            if ($success !== 1) {
                                throw new Exception("testenv.php did not complete successfully");
                            }
                        } else {
                            throw new Exception("testenv.php not found at $testenvPath");
                        }

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                    } catch (\Exception $e) {
                        error_log("Error in SetupDB: " . $e->getMessage());
                        $ret = [
                            'ret' => 1,
                            'status' => 'Errors occurred',
                            'errors' => $e->getMessage()
                        ];
                    }
                } else {
                    $ret = [
                        'ret' => 100,
                        'status' => 'Unknown action'
                    ];
                }

                break;
            }
    }

    return ($ret);
}
