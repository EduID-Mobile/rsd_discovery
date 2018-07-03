<?php
namespace Eduid\Rsd;

require_once('MDB2.php');

class Discovery {
    private $db;

    public function __construct($inifile) {
        // init DB
        try {
            $dbCfg = parse_ini_file($inifile, true);
        }
        catch (Exception $e) {
            // nothing we can do here
        }
        if ($dbCfg) {
            $dbCfg = $dbCfg["database"];
            $server = "localhost";
            $dbname = "rsd_index";

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
                die("cannot connect to database");
            }
        }
    }

    public function isActive() {
        if (!$this->db) {
            return false;
        }
        return true;
    }

    public function findProtocolList($input) {

        // error_log("hello");

        $dbKeys = array(
            "rsd" => "TEXT"
        );

        // load the protocols
        $this->data = [];

        $k = array_map(function ($e) { return "sp." . $e; }, array_keys($dbKeys));

        $list = $input->getBody();

        if (empty($list)) {
            // error_log("no list");
            throw new \RESTling\Exception\BadRequest();
        }

        $sqlstr = "SELECT DISTINCT " . implode(",", $k)
                . " FROM services sp, protocols p"
                . " WHERE p.service_id = sp.service_id AND p.rsd_name IN ("
                . implode(",", $this->quoteList($list))
                . ")";

        // error_log($sqlstr);

        $sth = $this->db->prepare($sqlstr);
        $res = $sth->execute();

        while ($row = $res->fetchRow(MDB2_FETCHMODE_ASSOC)) {
            if (empty($row["rsd"])) {
                //error_log("skip");
                continue;
            }
            try {
                $rsd = json_decode($row["rsd"], true);
            }
            catch (Exception $err) {
                continue;
            }
            // error_log($row["rsd"]);
            // error_log(json_encode($rsd));
            $apis = array_keys($rsd["apis"]);

            foreach ($list as $api) {
                if (!in_array($api, $apis)) {
                    $rsd = null;
                    break;
                }
            }
            if ($rsd) {
                $this->data[] = $rsd;
            }
        }

        $sth->free();
        if (empty($this->data)) {
            throw new \RESTling\Exception\NotFound();
        }
    }

    public function addFederationService($input) {
        $uri = $input->get("uri", "body");

        // if uri is NOT part of our services, we add it
        $sqlstr = "select uri from services where uri = ?";
        $sth = $this->db->prepare($sqlstr);
        $res = $sth->execute([$uri]);

        if(\PEAR::isError($res)) {
            $sth->free();
            throw new \RESTling\Exception\BrokenInput();
        }

        if (!($row = $res->fetchRow(MDB2_FETCHMODE_ASSOC))) {
            $sth->free();
            $sqlstr = "insert into services (uri) values (?)";
            $sth = $this->db->prepare($sqlstr, ["TEXT"]);
            $res = $sth->execute([$uri]);
        }
        else {
            $sth->free();
            throw new \RESTling\Exception\BadRequest();
        }
        $sth->free();
        throw new \RESTling\Exception\Created();
    }

    private function quoteList($list) {
        $retval = array();
        foreach ($list as $value) {
            if (is_string($value)) {
                $retval[] = $this->db->quote($value, 'text');
            }
        }
        return $retval;
    }

    private function mapToAttribute($objList, $attributeName, $quote=false) {
        $f = function ($e) use ($attributeName, $quote) {
            return $quote ? $this->db->quote($e[$attributeName]) : $e[$attributeName];
        };

        return array_map($f, $objList);
    }

    // for authorization
    public function getSharedKey($kid, $jku) {
        // load the token for the kid/jku pair
        // if jku is missing, the kid MUST identify the issuer
        // if kid is missing, then the JKU MUST refer to a unique key
        $where = [];
        $param = [];
        if (empty($kid) && empty($jku)) {
            throw new \RESTling\Exception\Forbidden();
        }
        if (empty($kid)) {
            $where[] = "kid IS NULL";
        }
        else {
            $where[] = "kid = ?";
            $param[] = $kid;
        }
        if (empty($jku)) {
            $where[] = "jku IS NULL";
        }
        else {
            $where[] = "jku = ?";
            $param[] = $jku;
        }

        $sqlstr = 'select service_key from service_keys where ' . join(" and ", $where);

        $sth = $this->db->prepare($sqlstr);
        $res = $sth->execute($param);

        if(\PEAR::isError($res)) {
            $sth->free();
            throw new \RESTling\Exception\BrokenInput();
        }

        if (!($row = $res->fetchRow(MDB2_FETCHMODE_ASSOC))) {
            $sth->free();
            throw new \RESTling\Exception\Forbidden();
        }

        // check if the result is unique
        if ($res->fetchRow(MDB2_FETCHMODE_ASSOC)) {
            $sth->free();
            throw new \RESTling\Exception\Forbidden();
        }

        $sth->free();

        return $row["service_key"];
    }
}
?>
