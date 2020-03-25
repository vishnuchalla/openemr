<?php
/**
 * CDC COVID-19 Person Under Investigation and Case Report Form
 *
 * @package   OpenEMR
 * @subpackage COVID19 Forms
 * @link      http://www.open-emr.org
 * @author    Robert Down
 * @copyright Copyright (c) 2020 Robert Down <rboertdown@live.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . "/../../globals.php";
require_once "$srcdir/api.inc";
require_once "$srcdir/patient.inc";

use OpenEMR\Common\Csrf\CsrfUtils;

$GLOBALS['twig']->getLoader()->addPath(__DIR__ . "/templates/", "cdccovid");

$patient = getPatientData($pid);


$current_status = [
    '1' => 'PUI testing pending',
    '2' => 'PUI tested negative',
    '3' => 'Presumptive case (positive local test), confirmatory testing pending',
    '4' => 'Presumptive case (positive local test), confirmatory tested negative†',
    '5' => 'Laboratory-confirmed case†',
];

$ethnicity = [
    '0' => 'Non-Hispanic Latino',
    '1' => 'Hispanic / Latino',
    '9' => 'Not Specified',
];

$sex = [
    '1' => 'Male',
    '2' => 'Female',
    '9' => 'Unknown',
    '0' => 'Other',
];

$race = [
    'race_asian' => 'Asian',
    'race_aian' => 'American Indian / Alaskan Native',
    'race_black' => 'Black',
    'race_nhpi' => 'Native Hawaiian / Other Pacific Islander',
    'race_white' => 'White',
    'race_unk' => 'Unknown',
    'race_other' => 'Other',
];

$vars = [
    'current_status' => $current_status,
    'ethnicity' => $ethnicity,
    'sex' => $sex,
    'patient' => $patient,
    'encounter' => $encounter,
    'race' => $race,
];

echo $GLOBALS['twig']->render("@cdccovid/form.html.twig", $vars);