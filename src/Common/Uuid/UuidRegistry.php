<?php

/**
 * UuidRegistry class
 *
 *    Generic support for UUID creation and use. Goal is to support:
 *     1. uuid for fhir resources
 *     2. uuid for future offsite support (using Timestamp-first COMB Codec for uuid,
 *        so can use for primary keys)
 *     3. uuid for couchdb docid
 *     4. other future use cases.
 *    The construct accepts an associative array in order to allow easy addition of
 *    fields and new sql columns in the uuid_registry table.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Gerhard Brink <gjdbrink@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Gerhard Brink <gjdbrink@gmail.com>
 * @copyright Copyright (c) 2020 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Common\Uuid;

use Ramsey\Uuid\Codec\TimestampFirstCombCodec;
use Ramsey\Uuid\Generator\CombGenerator;
use Ramsey\Uuid\UuidFactory;
use Ramsey\Uuid\Uuid;

class UuidRegistry
{

    // Maximum tries to create a unique uuid before failing (this should never happen)
    const MAX_TRIES = 100;

    private $table_name;      // table to check if uuid has already been used in
    private $table_id;        // the label of the column in above table that is used for id (defaults to 'id')
    private $table_vertical;  // false or array. if table is vertical, will store the critical columns (uuid set for matching columns)
    private $disable_tracker; // disable check and storage of uuid in the main uuid_registry table
    private $couchdb;         // blank or string (documents or ccda label for now, which represents the tables that hold the doc ids).
    private $document_drive;  // set to true if this is for labeling a document saved to drive
    private $mapped;          // set to true if this was mapped in uuid_mapping table

    public function __construct($associations = [])
    {
        $this->table_name = $associations['table_name'] ?? '';
        if (!empty($this->table_name)) {
            $this->table_id = $associations['table_id'] ?? 'id';
        } else {
            $this->table_id = '';
        }
        $this->table_vertical = $associations['table_vertical'] ?? false;
        $this->disable_tracker = $associations['disable_tracker'] ?? false;
        $this->couchdb = $associations['couchdb'] ?? '';
        if (!empty($associations['document_drive']) && $associations['document_drive'] === true) {
            $this->document_drive = 1;
        } else {
            $this->document_drive = 0;
        }
        if (!empty($associations['mapped']) && $associations['mapped'] === true) {
            $this->mapped = 1;
        } else {
            $this->mapped = 0;
        }
    }

    /**
     * @return string
     */
    public function createUuid()
    {
        $isUnique = false;
        $i = 0;
        while (!$isUnique) {
            $i++;
            if ($i > 1) {
                // There was a uuid creation collision, so need to try again.
                error_log("OpenEMR Warning: There was a collision when creating a unique UUID. This is try number " . $i . ". Will try again.");
            }
            if ($i > self::MAX_TRIES) {
                // This error should never happen. If so, then the random generation of the
                //  OS is compromised and no use continuing to run OpenEMR.
                error_log("OpenEMR Error: Unable to create a unique UUID");
                exit;
            }

            // Create uuid using the Timestamp-first COMB Codec, so can use for primary keys
            //  (since first part is timestamp, it is naturally ordered; the rest is from uuid4, so is random)
            //  reference:
            //    https://uuid.ramsey.dev/en/latest/customize/timestamp-first-comb-codec.html#customize-timestamp-first-comb-codec
            $factory = new UuidFactory();
            $codec = new TimestampFirstCombCodec($factory->getUuidBuilder());
            $factory->setCodec($codec);
            $factory->setRandomGenerator(new CombGenerator(
                $factory->getRandomGenerator(),
                $factory->getNumberConverter()
            ));
            $timestampFirstComb = $factory->uuid4();
            $uuid = $timestampFirstComb->getBytes();

            /** temp debug stuff
            error_log(bin2hex($uuid)); // log hex uuid
            error_log(bin2hex($timestampFirstComb->getBytes())); // log hex uuid
            error_log($timestampFirstComb->toString()); // log string uuid
            $test_uuid = (\Ramsey\Uuid\Uuid::fromBytes($uuid))->toString(); // convert byte uuid to string and log below
            error_log($test_uuid);
            error_log(bin2hex((\Ramsey\Uuid\Uuid::fromString($test_uuid))->getBytes())); // convert string uuid to byte and log hex
             */

            // Check to ensure uuid is unique in uuid_registry (unless $this->disable_tracker is set to true)
            if (!$this->disable_tracker) {
                $checkUniqueRegistry = sqlQueryNoLog("SELECT * FROM `uuid_registry` WHERE `uuid` = ?", [$uuid]);
            }
            if (empty($checkUniqueRegistry)) {
                if (!empty($this->table_name)) {
                    // If using $this->table_name, then ensure uuid is unique in that table
                    $checkUniqueTable = sqlQueryNoLog("SELECT * FROM `" . $this->table_name . "` WHERE `uuid` = ?", [$uuid]);
                    if (empty($checkUniqueTable)) {
                        $isUnique = true;
                    }
                } elseif ($this->document_drive === 1) {
                    // If using for document labeling on drive, then ensure drive_uuid is unique in documents table
                    $checkUniqueTable = sqlQueryNoLog("SELECT * FROM `documents` WHERE `drive_uuid` = ?", [$uuid]);
                    if (empty($checkUniqueTable)) {
                        $isUnique = true;
                    }
                } else {
                    $isUnique = true;
                }
            }
        }

        // Insert the uuid into uuid_registry (unless $this->disable_tracker is set to true)
        if (!$this->disable_tracker) {
            if (!$this->table_vertical) {
                sqlQueryNoLog("INSERT INTO `uuid_registry` (`uuid`, `table_name`, `table_id`, `couchdb`, `document_drive`, `mapped`, `created`) VALUES (?, ?, ?, ?, ?, ?, NOW())", [$uuid, $this->table_name, $this->table_id, $this->couchdb, $this->document_drive, $this->mapped]);
            } else {
                sqlQueryNoLog("INSERT INTO `uuid_registry` (`uuid`, `table_name`, `table_id`, `table_vertical`, `couchdb`, `document_drive`, `mapped`, `created`) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())", [$uuid, $this->table_name, $this->table_id, json_encode($this->table_vertical), $this->couchdb, $this->document_drive, $this->mapped]);
            }
        }

        // Return the uuid
        return $uuid;
    }

    // Generic function to create missing uuids in a sql table (table needs an `id` column to work)
    public function createMissingUuids()
    {
        // Empty should be NULL, but to be safe also checking for empty and null bytes
        $resultSet = sqlStatementNoLog("SELECT * FROM `" . $this->table_name . "` WHERE `uuid` IS NULL OR `uuid` = '' OR `uuid` = '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0'");
        while ($row = sqlFetchArray($resultSet)) {
            if (!$this->table_vertical) {
                // standard case, add missing uuid
                sqlQueryNoLog("UPDATE `" . $this->table_name . "` SET `uuid` = ? WHERE `" . $this->table_id . "` = ?", [$this->createUuid(), $row[$this->table_id]]);
            } else {
                // more complicated case where setting uuid in a vertical table. In this case need a uuid for each combination of table columns stored in $this->table_vertical array
                $stringQuery = "";
                $arrayQuery = [];
                $prependAnd = false;
                foreach ($this->table_vertical as $column) {
                    if ($prependAnd) {
                        $stringQuery .= " AND `" . $column . "` = ? ";
                    } else {
                        $stringQuery .= " `" . $column . "` = ? ";
                    }
                    $arrayQuery[] = $row[$column];
                    $prependAnd = true;
                }
                // First, see if it has already been set
                $setUuid = sqlQueryNoLog("SELECT `uuid` FROM `" . $this->table_name . "` WHERE `uuid` IS NOT NULL AND `uuid` != '' AND `uuid` != '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0' AND " . $stringQuery, $arrayQuery)['uuid'];
                if (!empty($setUuid)) {
                    // Already set
                    array_unshift($arrayQuery, $setUuid);
                } else {
                    // Not already set, so create
                    array_unshift($arrayQuery, $this->createUuid());
                }
                // Now populate
                sqlQueryNoLog("UPDATE `" . $this->table_name . "` SET `uuid` = ? WHERE " . $stringQuery, $arrayQuery);
            }
        }
    }

    // Generic function to see if there are missing uuids in a sql table (table needs an `id` column to work)
    public function tableNeedsUuidCreation()
    {
        // Empty should be NULL, but to be safe also checking for empty and null bytes
        $resultSet = sqlQueryNoLog("SELECT count(`" . $this->table_id . "`) as `total` FROM `" . $this->table_name . "` WHERE `uuid` IS NULL OR `uuid` = '' OR `uuid` = '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0'");
        if ($resultSet['total'] > 0) {
            return true;
        }
        return false;
    }

    /**
     * Converts a UUID byte value to a string representation
     * @return the UUID string value
     */
    public static function uuidToString($uuidBytes)
    {
        return Uuid::fromBytes($uuidBytes)->toString();
    }

    /**
     * Converts a UUID string to a bytes representation
     * @return the UUID bytes value
     */
    public static function uuidToBytes($uuidString)
    {
        return Uuid::fromString($uuidString)->getBytes();
    }

    /**
     * Check if UUID String is Valid
     * @return boolean
     */
    public static function isValidStringUUID($uuidString)
    {
        return (Uuid::isValid($uuidString));
    }

    /**
     * Check if UUID Brinary is Empty
     * @return boolean
     */
    public static function isEmptyBinaryUUID($uuidString)
    {
        return (empty($uuidString) || ($uuidString == '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0'));
    }
}
