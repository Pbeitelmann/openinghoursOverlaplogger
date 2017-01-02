<?php
class OpeninghoursOverlapLogger {

    /**
     * @var array
     */
    private $config = [];

    /**
     * @var PDO
     */
    private $lfDb;
    
    /**
     * @var string
     */
    private $restaurantId;


    public function __construct($restaurantId = null)
    {
        $this->restaurantId = $restaurantId;
    }

    /**
     * @desc Parses CLI params
     */
    public function parseParams() {
        $options = getopt("i::", ["id::"]);
        if(isset($options["i"])) {
            $this->restaurantId = $options["i"];
        }

        if(isset($options["id"])) {
            $this->restaurantId = $options["id"];
        }
    }

    /**
     * @desc sets the config file
     */
    public function setConfig() {
        $this->config = parse_ini_file("openinghoursOverlapLogger.conf", true);
    }

    /**
     * @desc Prepares the database handles for later usage
     */
    public function prepareDbHandles() {
        $lfDsn = "mysql:host=" . $this->config["lieferando"]["host"] . ";dbname=" . $this->config["lieferando"]["dbName"];
        $this->lfDb = new PDO($lfDsn, $this->config["lieferando"]["user"], $this->config["lieferando"]["password"], [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    /**
     * @desc entry point for the application
     */
    public function execute() {

        if(isset($this->restaurantId)) {
            $invalidOpeninghours[$this->restaurantId] = $this->check($this->restaurantId);
        } else {
            $invalidOpeninghours = $this->checkAll();
        }
        foreach($invalidOpeninghours as $id => $invalidOpeninghour) {
            if(!empty($invalidOpeninghour["normal"])) {
                $this->logToFile("normalOpeninghoursOverlaps.txt", $invalidOpeninghour["normal"], $id);
            }
            if(!empty($invalidOpeninghour["special"])) {
                $this->logToFile("specialOpeninghoursOverlaps.txt", $invalidOpeninghour["special"], $id);
            }
        }
    }


    /**
     * @desc triggered if no restaurant ID is set. Will migrate all active Restaurants
     * @return bool | array
     */
    public function checkAll() {
        $lfFetch = $this->lfDb->prepare("SELECT `id`
                                            FROM `restaurants`
                                            WHERE `status`
                                            NOT IN ('11','18', '19', '30', '31')");
        $lfFetch->execute();
        $activeRestaurantIds = $lfFetch->fetchAll();
        $count = count($activeRestaurantIds);
        $i = 1;

        printf("Checking " . $count . " Restaurants." . PHP_EOL);
        $invalidOpeninghours = [];
        foreach($activeRestaurantIds as $entry) {
            printf("(" . $i . " / " . $count . ")" . PHP_EOL);
            $invalidOpeninghours[$entry["id"]] = $this->check($entry["id"]);
            $i++;
        }

        printf("Finished checking " . $count . " Restaurants." . PHP_EOL);
        return $invalidOpeninghours;
    }

    /**
     * @param $lieferandoId
     * @desc Checks the normal openinghours of a single Lieferando restaurant for overlaps
     * @return bool | array
     */
    public function check($lieferandoId) {
        $mapping = $this->getRestaurantMapping($lieferandoId);

        printf("Checking Restaurant with Lieferando ID: " . $lieferandoId . PHP_EOL);
        if(is_null($mapping)) {
            printf("No Mapping found for Lieferando ID: " . $lieferandoId . " Skipping." . PHP_EOL);
            return false;
        }
        $invalidOpeninghours = [];
        if($mapping["status"] == "OK") {
            $invalidOpeninghours["normal"] = $this->checkNormalOpeninghourOverlaps($mapping, "restaurant_openings");
            $invalidOpeninghours["special"] = $this->checkSpecialOpeninghourOverlaps($mapping, "restaurant_openings_special");

            return $invalidOpeninghours;
        }

        return false;
    }

    /**
     * @desc Gets the Restaurant mapping for a specified Lieferando ID
     * @param $lieferandoId
     * @return mixed
     */
    public function getRestaurantMapping($lieferandoId) {
        $ch = curl_init($this->config["restaurantMappingApi"]["url"] . "/restaurant?lieferando_id=" . $lieferandoId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        $data = curl_exec($ch);
        curl_close($ch);

        $mappingResult = json_decode($data, true);

        return $mappingResult;
    }

    /**
     * @desc Returns the normal openinghours of a Lieferando Restaurant
     * @param $restaurantId
     * @return mixed
     */
    public function getLieferandoOpenings($restaurantId) {
        $lfFetch = $this->lfDb->prepare("SELECT * FROM `restaurant_openings` WHERE `restaurantId` = :restaurantId AND `deleted` = 0");
        $lfFetch->execute([":restaurantId" => $restaurantId]);
        $openings = $lfFetch->fetchAll();

        return $openings;
    }

    /**
     * @desc Returns the active special openinghours of a Lieferando Restaurant
     * @param $restaurantId
     * @return mixed
     */
    public function getLieferandoOpeningsSpecial($restaurantId) {
        $lfFetch = $this->lfDb->prepare("SELECT *
                                            FROM `restaurant_openings_special`
                                            WHERE `restaurantId` = :restaurantId
                                            AND `deleted` = 0
                                            AND `specialDate` > NOW()");
        $lfFetch->execute([":restaurantId" => $restaurantId]);
        $specialOpenings = $lfFetch->fetchAll();

        return $specialOpenings;
    }

    /**
     * @desc checks the the normal openinghours of a Lieferando Restaurant for overlaps
     * @param $mapping
     * @return array
     */
    public function checkNormalOpeninghourOverlaps($mapping, $tableName) {
        printf("Checking opening hours ({$tableName}) ...." . PHP_EOL);
        $invalidDates = [];
        $dayMap = [
            0 => "Sunday",
            1 => "Monday",
            2 => "Tuesday",
            3 => "Wednesday",
            4 => "Thursday",
            5 => "Friday",
            6 =>"Saturday",
        ];

        $lieferandoOpenings = $this->getLieferandoOpenings($mapping["result"]["data"]["lieferando_id"]);

        foreach($lieferandoOpenings as $opening) {
            if($opening["day"] == 10) {
                continue;
            }
            if($this->checkForOverlap($lieferandoOpenings, $opening) == true) {
                $day = $dayMap[$opening["day"]];
                $invalidDates[$day][] = [
                    "startTime" => $opening["startTime"],
                    "endTime" => $opening["endTime"]
                ];
            }
        }

        return $invalidDates;
    }

    /**
     * @desc Updates the special openinghours of a Takeaway Restaurant
     * @param $mapping
     * @return array
     */
    public function checkSpecialOpeninghourOverlaps($mapping, $tableName) {
        printf("Checking special opening hours ({$tableName})...." . PHP_EOL);

        $lieferandoOpeningsSpecial = $this->getLieferandoOpeningsSpecial($mapping["result"]["data"]["lieferando_id"]);

        $invalidDates = [];
        foreach($lieferandoOpeningsSpecial as $specialOpening) {
            if($this->checkSpecialForOverlap($lieferandoOpeningsSpecial, $specialOpening) == true) {
                $invalidDates[$specialOpening["specialDate"]][] = [
                    "startTime" => $specialOpening["startTime"],
                    "endTime" => $specialOpening["endTime"]
                ];
            }
        }

        return $invalidDates;
    }

    /**
     * @desc Checks if the supplied opening overlaps with any other openings supplied via lieferandoOpenings
     * @param $lieferandoOpenings
     * @param $opening
     * @return bool
     */
    public function checkForOverlap($lieferandoOpenings, $opening) {
        foreach ($lieferandoOpenings as $o) {
            if ($o["id"] != $opening["id"] && $o["day"] == $opening["day"]) {
                $overlap = $this->overlap(
                    $o["startTime"],
                    $o["endTime"],
                    $opening["startTime"],
                    $opening["endTime"]
                );
                if ($overlap) {
                    return true;
                }
            }
        }
    }

    /**
     * @desc Checks if the supplied special opening overlaps with any other special openings
     *       supplied via lieferandoSpecialOpenings
     * @param $lieferandoSpecialOpenings
     * @param $opening
     * @return bool
     */
    public function checkSpecialForOverlap($lieferandoSpecialOpenings, $opening) {
        foreach ($lieferandoSpecialOpenings as $o) {
            if ($o["id"]!=$opening["id"] && $o["specialDate"]==$opening["specialDate"]) {
                $overlap = $this->overlap(
                    $o["startTime"],
                    $o["endTime"],
                    $opening["startTime"],
                    $opening["endTime"]
                );
                if ($overlap) {
                    return true;
                }
            }
        }
    }

    /**
     * @desc Courtesy of our Enschede friends
     *       Compares new Start & End Time with existing Start & End time to check if they overlap
     * @param $start_existing
     * @param $end_existing
     * @param $start_new
     * @param $end_new
     * @return bool
     */
    public function overlap($start_existing,$end_existing,$start_new,$end_new) {
        $overlap = false;

        $start_existing = preg_replace('/[^0-9]/','',$start_existing);
        $end_existing = preg_replace('/[^0-9]/','',$end_existing);
        $start_new = preg_replace('/[^0-9]/','',$start_new);
        $end_new = preg_replace('/[^0-9]/','',$end_new);

        # the existing range is entirely within the new range
        if ($start_new <= $start_existing and $end_new >= $end_existing) {
            $overlap = true;
        }

        # start of the new range is within the existing range
        if ($start_new >= $start_existing and $start_new < $end_existing) {
            $overlap = true;
        }

        # end of the new range is within the existing range
        if ($end_new > $start_existing and $end_new < $end_existing) {
            $overlap = true;
        }

        # range closes everything
        if (($start_new==0 and $end_new==0) or ($start_existing==0 and $end_existing==0)) {
            $overlap = true;
        }

        return $overlap;
    }

    /**
     * @param $fileName
     * @param array $invalidOpeninghours
     */
    public function logToFile($fileName, array $invalidOpeninghours, $restaurantId) {
        /*foreach ($invalidOpeninghours as $entry) {
            $string = "[" . $restaurantId . "]" . print_r($entry, true);
            $string = str_replace(["Array", "(", ")", "=>"], "", $string);
            $string = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $string);
            file_put_contents($fileName,$string, FILE_APPEND);
        }*/

        $string = "[" . $restaurantId . "]" . print_r($invalidOpeninghours, true);
        $string = str_replace(["Array", "(", ")", "=>"], "", $string);
        $string = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $string);
        file_put_contents($fileName,$string, FILE_APPEND);
    }
}