<?php
namespace Eduid\Rsd;

use \Curler\Request as Curler;

require_once('MDB2.php');

class Crawler {
    private $db;

    public function __construct($inifile) {
        // init DB
        try {
            $dbCfg = parse_ini_file($inifile, true);
            $dbCfg = $dbCfg["database"];
        }
        catch (Exception $e) {
            die("Cannot load ini file " . $err->getMessage());
        }
        if ($dbCfg) {
            $server = "localhost";
            $dbname = "eduid";

            if (array_key_exists("server", $dbCfg)) {
                $server = $dbCfg["server"];
            }
            if (array_key_exists("name", $dbCfg)) {
                $dbname = $dbCfg["name"];
            }

            $dsn = ["phptype"  => $dbCfg["driver"],
                    "username" => $dbCfg["user"],
                    "password" => $dbCfg["pass"],
                    "hostspec" => $server,
                    "database" => $dbname];
            $options = [];

            $this->db =& \MDB2::factory($dsn,$options);

            if (\PEAR::isError($this->db)) {
                die("Cannot connect to database");
            }
        }
    }

    public function checkServices() {
        $services = [];
        $sqlstr = 'select * from services';
        $sth = $this->db->prepare($sqlstr);
        $res = $sth->execute();

        while ($row = $res->fetchRow(MDB2_FETCHMODE_ASSOC)) {
            $services[] = $row;
        }
        $sth->free();
        foreach ($services as $serviceInfo) {
            $this->checkService($serviceInfo);
        }
    }

    protected function checkService($serviceInfo) {
        $dbFields = [
            "service_id" => 'INTEGER',
            "uri"        => 'TEXT',
            "rsd"        => 'TEXT',
            "ttl"        => 'INTEGER',
            "last_update"=> 'INTEGER',
            "checksum"   => 'TEXT'
        ];

        $now = time();
        $exp = $serviceInfo["ttl"] + $serviceInfo["last_update"];

        // $exp = 0; // for testing ignore the ttl.

        if ($exp < $now) {
            // need to reload the service
            $crawler = new Curler($serviceInfo["uri"]);
            $crawler->debugConnection();
            $crawler->ignoreSSLCertificate();


            $crawler->setPathInfo("services.txt");
            $crawler->get();

            if ($crawler->getStatus() == 200) {
                // check if something has changed
                $body = $crawler->getBody();
                $lines = explode("\n", $body);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) && strpos($line, '#') === 0) {
                        continue;
                    }

                    if (strpos($line, ';') > 0) {
                        list($type, $path) = explode(";", $line,2);
                        $type = trim($type);
                        $path = trim($path);
                        if ($type === "application/x-rsd+json") {
                            break;
                        }
                        elseif ($type === "application/x-rsd+yaml") {
                            break;
                        }
                    }
                    elseif (strpos($line, ':') > 0) {
                        list($type, $path) = explode(":", $line,2);
                        if (trim($type) == "ttl") { // server sets a ttl
                            $serviceInfo["ttl"] = trim($path);
                        }
                    }
                    $path = '';
                }

                if (empty($path)) {
                    // check if we have a JSON object
                    $serviceInfo = $this->processRsd($body, $serviceInfo);
                }
                else {
                    if (strpos($path, "http://") === 0 || strpos($path, "https://") === 0) {
                        // use full URLs
                        $crawler->setUrl($path);
                    }
                    else {
                        // use relative paths
                        $crawler->setPathInfo($path);
                    }

                    $crawler->get();

                    if ($crawler->getStatus() == 200) {
                        $serviceInfo = $this->processRsd($crawler->getBody(), $serviceInfo);
                    }
                }
            }

            $serviceInfo["last_update"] = time();
            $fld = [];
            $val = [];
            $typ = [];
            foreach ($serviceInfo as $k => $v) {
                if ($k != 'service_id' && $k != 'uri') {
                    $fld[] = "$k = ?";
                    $val[] = $v;
                    $typ[] = $dbFields[$k];
                }
            }

            $val[] = $serviceInfo["service_id"];
            $typ[] = $dbFields["service_id"];
            $sqlstr = 'update services set '. join(',', $fld) . ' where service_id = ?';

            $sth = $this->db->prepare($sqlstr, $typ);
            $res = $sth->execute($val);
            if(\PEAR::isError($res)) {
                // something is wrong?
                error_log($res->getMessage());
            }
            $sth->free();
        }
    }

    protected function processRsd($rsdBody, $serviceInfo) {
        $body = trim($rsdBody);
        if (!empty($body)) {
            try {
                $rsd = json_decode($body, true);
            }
            catch (Exception $err) {
                // test for YAML
                try {
                    $rsd = \yaml_parse($body);
                }
                catch (Exception $err) {
                    // nothing to do
                }
            }
        }
        if (!empty($rsd)) {
            $chk = md5($body);
            if ($chk != $serviceInfo["checksum"]) {
                // we update only if the RSD has changed
                $serviceInfo["rsd"] = $rsd;
                $serviceInfo["checksum"] = $chk;

                // clear the notes from the RSD
                // $rsd = $this->stripApiNotes($rsd);
                $serviceInfo["rsd"] = json_encode($rsd);

                // now process the exposed APIs
                if (array_key_exists("apis", $rsd) && !empty($rsd["apis"])) {
                    $this->processApis($rsd["apis"], $serviceInfo);
                }
            }
        }
        return $serviceInfo;
    }

    protected function processApis($apis, $serviceInfo) {
        $sid = $serviceInfo["service_id"];

        // drop all service protocols if any
        $sqlstr = "delete from protocols where service_id = ?";
        $sth = $this->db->prepare($sqlstr, ["INTEGER"]);
        $res = $sth->execute([$sid]);
        if(\PEAR::isError($res)) {
            // something is wrong?
            error_log($res->getMessage());
        }
        $sth->free();

        $sqlstr = "insert into protocols (service_id, rsd_name) values (?, ?)";
        $sth = $this->db->prepare($sqlstr, ["INTEGER", "TEXT"]);

        foreach ($apis as $api => $v) {
            $res = $sth->execute([$sid, $api]);
            if(\PEAR::isError($res)) {
                // something is wrong?
                error_log($res->getMessage());
            }
        }
        $sth->free();
    }
}
?>
