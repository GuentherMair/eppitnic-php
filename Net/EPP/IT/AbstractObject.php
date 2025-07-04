<?php

require_once 'Net/EPP/IT/log_severity.php';

/**
 * An abstract class for other EPP objects (session, contact, domain).
 *
 * It provides:
 *  - public variables available inside all objects
 *  - a generic constructor and protected variables for Client and Storage
 *  - a generic error code handler
 *  - a generic ExecuteQuery method
 *
 * PHP version 5
 *
 * LICENSE:
 *
 * Copyright (c) 2009, Günther Mair <guenther.mair@hoslo.ch>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1) Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 * 2) Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 * 3) Neither the name of Günther Mair nor the names of its contributors may be
 *    used to endorse or promote products derived from this software without
 *    specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @category    Net
 * @package     Net_EPP_IT_AbstractObject
 * @author      Günther Mair <guenther.mair@hoslo.ch>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 *
 * $Id: AbstractObject.php 124 2010-10-09 12:59:25Z gunny $
 */
abstract class Net_EPP_IT_AbstractObject
{
  protected $client;
  protected $storage;

  protected $trues = array("true", "TRUE", 1);
  protected $falses = array("false", "FALSE", 0, null);

  public    $debug = LOG_WARNING;

  public    $xmlQuery;  // xml query string
  public    $result;    // HTTP response string
  public    $xmlResult; // parsed reponse (SimpleXMLElement may be incomplete)

  public    $svCode;
  public    $svMsg;
  public    $svTRID;
  public    $extValueReasonCode;
  public    $extValueReason;

  protected $iso3166_1 = array(
              // "A1" => "Anonymous Proxy",
              // "A2" => "Satellite Provider",
              // "O1" => "Other Country",
              "AD" => "Andorra",
              "AE" => "United Arab Emirates",
              "AF" => "Afghanistan",
              "AG" => "Antigua and Barbuda",
              "AI" => "Anguilla",
              "AL" => "Albania",
              "AM" => "Armenia",
              "AN" => "Netherlands Antilles",
              "AO" => "Angola",
              "AP" => "Asia/Pacific Region",
              "AQ" => "Antarctica",
              "AR" => "Argentina",
              "AS" => "American Samoa",
              "AT" => "Austria",
              "AU" => "Australia",
              "AW" => "Aruba",
              "AX" => "Aland Islands",
              "AZ" => "Azerbaijan",
              "BA" => "Bosnia and Herzegovina",
              "BB" => "Barbados",
              "BD" => "Bangladesh",
              "BE" => "Belgium",
              "BF" => "Burkina Faso",
              "BG" => "Bulgaria",
              "BH" => "Bahrain",
              "BI" => "Burundi",
              "BJ" => "Benin",
              "BM" => "Bermuda",
              "BN" => "Brunei Darussalam",
              "BO" => "Bolivia",
              "BR" => "Brazil",
              "BS" => "Bahamas",
              "BT" => "Bhutan",
              "BV" => "Bouvet Island",
              "BW" => "Botswana",
              "BY" => "Belarus",
              "BZ" => "Belize",
              "CA" => "Canada",
              "CC" => "Cocos (Keeling) Islands",
              "CD" => "Congo, The Democratic Republic of the",
              "CF" => "Central African Republic",
              "CG" => "Congo",
              "CH" => "Switzerland",
              "CI" => "Cote d'Ivoire",
              "CK" => "Cook Islands",
              "CL" => "Chile",
              "CM" => "Cameroon",
              "CN" => "China",
              "CO" => "Colombia",
              "CR" => "Costa Rica",
              "CU" => "Cuba",
              "CV" => "Cape Verde",
              "CX" => "Christmas Island",
              "CY" => "Cyprus",
              "CZ" => "Czech Republic",
              "DE" => "Germany",
              "DJ" => "Djibouti",
              "DK" => "Denmark",
              "DM" => "Dominica",
              "DO" => "Dominican Republic",
              "DZ" => "Algeria",
              "EC" => "Ecuador",
              "EE" => "Estonia",
              "EG" => "Egypt",
              "EH" => "Western Sahara",
              "ER" => "Eritrea",
              "ES" => "Spain",
              "ET" => "Ethiopia",
              "EU" => "Europe",
              "FI" => "Finland",
              "FJ" => "Fiji",
              "FK" => "Falkland Islands (Malvinas)",
              "FM" => "Micronesia, Federated States of",
              "FO" => "Faroe Islands",
              "FR" => "France",
              "GA" => "Gabon",
              "GB" => "United Kingdom",
              "GD" => "Grenada",
              "GE" => "Georgia",
              "GF" => "French Guiana",
              "GG" => "Guernsey",
              "GH" => "Ghana",
              "GI" => "Gibraltar",
              "GL" => "Greenland",
              "GM" => "Gambia",
              "GN" => "Guinea",
              "GP" => "Guadeloupe",
              "GQ" => "Equatorial Guinea",
              "GR" => "Greece",
              "GS" => "South Georgia and the South Sandwich Islands",
              "GT" => "Guatemala",
              "GU" => "Guam",
              "GW" => "Guinea-Bissau",
              "GY" => "Guyana",
              "HK" => "Hong Kong",
              "HM" => "Heard Island and McDonald Islands",
              "HN" => "Honduras",
              "HR" => "Croatia",
              "HT" => "Haiti",
              "HU" => "Hungary",
              "ID" => "Indonesia",
              "IE" => "Ireland",
              "IL" => "Israel",
              "IM" => "Isle of Man",
              "IN" => "India",
              "IO" => "British Indian Ocean Territory",
              "IQ" => "Iraq",
              "IR" => "Iran, Islamic Republic of",
              "IS" => "Iceland",
              "IT" => "Italy",
              "JE" => "Jersey",
              "JM" => "Jamaica",
              "JO" => "Jordan",
              "JP" => "Japan",
              "KE" => "Kenya",
              "KG" => "Kyrgyzstan",
              "KH" => "Cambodia",
              "KI" => "Kiribati",
              "KM" => "Comoros",
              "KN" => "Saint Kitts and Nevis",
              "KP" => "Korea, Democratic People's Republic of",
              "KR" => "Korea, Republic of",
              "KW" => "Kuwait",
              "KY" => "Cayman Islands",
              "KZ" => "Kazakhstan",
              "LA" => "Lao People's Democratic Republic",
              "LB" => "Lebanon",
              "LC" => "Saint Lucia",
              "LI" => "Liechtenstein",
              "LK" => "Sri Lanka",
              "LR" => "Liberia",
              "LS" => "Lesotho",
              "LT" => "Lithuania",
              "LU" => "Luxembourg",
              "LV" => "Latvia",
              "LY" => "Libyan Arab Jamahiriya",
              "MA" => "Morocco",
              "MC" => "Monaco",
              "MD" => "Moldova, Republic of",
              "ME" => "Montenegro",
              "MG" => "Madagascar",
              "MH" => "Marshall Islands",
              "MK" => "Macedonia",
              "ML" => "Mali",
              "MM" => "Myanmar",
              "MN" => "Mongolia",
              "MO" => "Macao",
              "MP" => "Northern Mariana Islands",
              "MQ" => "Martinique",
              "MR" => "Mauritania",
              "MS" => "Montserrat",
              "MT" => "Malta",
              "MU" => "Mauritius",
              "MV" => "Maldives",
              "MW" => "Malawi",
              "MX" => "Mexico",
              "MY" => "Malaysia",
              "MZ" => "Mozambique",
              "NA" => "Namibia",
              "NC" => "New Caledonia",
              "NE" => "Niger",
              "NF" => "Norfolk Island",
              "NG" => "Nigeria",
              "NI" => "Nicaragua",
              "NL" => "Netherlands",
              "NO" => "Norway",
              "NP" => "Nepal",
              "NR" => "Nauru",
              "NU" => "Niue",
              "NZ" => "New Zealand",
              "OM" => "Oman",
              "PA" => "Panama",
              "PE" => "Peru",
              "PF" => "French Polynesia",
              "PG" => "Papua New Guinea",
              "PH" => "Philippines",
              "PK" => "Pakistan",
              "PL" => "Poland",
              "PM" => "Saint Pierre and Miquelon",
              "PN" => "Pitcairn",
              "PR" => "Puerto Rico",
              "PS" => "Palestinian Territory",
              "PT" => "Portugal",
              "PW" => "Palau",
              "PY" => "Paraguay",
              "QA" => "Qatar",
              "RE" => "Reunion",
              "RO" => "Romania",
              "RS" => "Serbia",
              "RU" => "Russian Federation",
              "RW" => "Rwanda",
              "SA" => "Saudi Arabia",
              "SB" => "Solomon Islands",
              "SC" => "Seychelles",
              "SD" => "Sudan",
              "SE" => "Sweden",
              "SG" => "Singapore",
              "SH" => "Saint Helena",
              "SI" => "Slovenia",
              "SJ" => "Svalbard and Jan Mayen",
              "SK" => "Slovakia",
              "SL" => "Sierra Leone",
              "SM" => "San Marino",
              "SN" => "Senegal",
              "SO" => "Somalia",
              "SR" => "Suriname",
              "ST" => "Sao Tome and Principe",
              "SV" => "El Salvador",
              "SY" => "Syrian Arab Republic",
              "SZ" => "Swaziland",
              "TC" => "Turks and Caicos Islands",
              "TD" => "Chad",
              "TF" => "French Southern Territories",
              "TG" => "Togo",
              "TH" => "Thailand",
              "TJ" => "Tajikistan",
              "TK" => "Tokelau",
              "TL" => "Timor-Leste",
              "TM" => "Turkmenistan",
              "TN" => "Tunisia",
              "TO" => "Tonga",
              "TR" => "Turkey",
              "TT" => "Trinidad and Tobago",
              "TV" => "Tuvalu",
              "TW" => "Taiwan",
              "TZ" => "Tanzania, United Republic of",
              "UA" => "Ukraine",
              "UG" => "Uganda",
              "UM" => "United States Minor Outlying Islands",
              "US" => "United States",
              "UY" => "Uruguay",
              "UZ" => "Uzbekistan",
              "VA" => "Holy See (Vatican City State)",
              "VC" => "Saint Vincent and the Grenadines",
              "VE" => "Venezuela",
              "VG" => "Virgin Islands, British",
              "VI" => "Virgin Islands, U.S.",
              "VN" => "Vietnam",
              "VU" => "Vanuatu",
              "WF" => "Wallis and Futuna",
              "WS" => "Samoa",
              "YE" => "Yemen",
              "YT" => "Mayotte",
              "ZA" => "South Africa",
              "ZM" => "Zambia",
              "ZW" => "Zimbabwe");

  protected $iso3166_1EU = array(
              "BE" => "Belgium",
              "BG" => "Bulgaria",
              "DK" => "Denmark",
              "DE" => "Germany",
              "EE" => "Estonia",
              "FI" => "Finland",
              "FR" => "France",
              "GR" => "Greece",
              "IE" => "Ireland",
              "IT" => "Italy",
              "LT" => "Lithuania",
              "LV" => "Latvia",
              "LU" => "Luxembourg",
              "MT" => "Malta",
              "NL" => "Netherlands",
              "AT" => "Austria",
              "PL" => "Poland",
              "PT" => "Portugal",
              "RO" => "Romania",
              "SE" => "Sweden",
              "SK" => "Slovakia",
              "SI" => "Slovenia",
              "ES" => "Spain",
              "CZ" => "Czech Republic",
              "HU" => "Hungary",
              "GB" => "United Kingdom",
              "CY" => "Cyprus");

  protected $iso3166_2IT = array(
              "AG" => "Agrigento",
              "AL" => "Alessandria",
              "AN" => "Ancona",
              "AO" => "Aosta",
              "AR" => "Arezzo",
              "AP" => "Ascoli Piceno",
              "AT" => "Asti",
              "AV" => "Avellino",
              "BA" => "Bari",
              "BT" => "Barletta-Andria-Trani",
              "BL" => "Belluno",
              "BN" => "Benevento",
              "BG" => "Bergamo",
              "BI" => "Biella",
              "BO" => "Bologna",
              "BZ" => "Bolzano",
              "BS" => "Brescia",
              "BR" => "Brindisi",
              "CA" => "Cagliari",
              "CL" => "Caltanissetta",
              "CB" => "Campobasso",
              "CI" => "Carbonia-Iglesias",
              "CE" => "Caserta",
              "CT" => "Catania",
              "CZ" => "Catanzaro",
              "CH" => "Chieti",
              "CO" => "Como",
              "CS" => "Cosenza",
              "CR" => "Cremona",
              "KR" => "Crotone",
              "CN" => "Cuneo",
              "EN" => "Enna",
              "FM" => "Fermo",
              "FE" => "Ferrara",
              "FI" => "Firenze",
              "FG" => "Foggia",
              "FC" => "Forli-Cesena",
              "FR" => "Frosinone ",
              "GE" => "Genova",
              "GO" => "Gorizia",
              "GR" => "Grosseto",
              "IM" => "Imperia",
              "IS" => "Isernia",
              "AQ" => "L'Aquila",
              "SP" => "La Spezia",
              "LT" => "Latina",
              "LE" => "Lecce",
              "LC" => "Lecco",
              "LI" => "Livorno",
              "LO" => "Lodi",
              "LU" => "Lucca",
              "MC" => "Macerata",
              "MN" => "Mantova",
              "MS" => "Massa-Carrara",
              "MT" => "Matera",
              "MA" => "Medio Campidano",
              "ME" => "Messina",
              "MI" => "Milano",
              "MO" => "Modena",
              "MB" => "Monza e Brianza",
              "NA" => "Napoli",
              "NO" => "Novara",
              "NU" => "Nuoro",
              "OG" => "Ogliastra",
              "OL" => "Olbia-Tempio",
              "OR" => "Oristano",
              "PD" => "Padova",
              "PA" => "Palermo",
              "PR" => "Parma",
              "PV" => "Pavia",
              "PG" => "Perugia",
              "PS" => "Pesaro e Urbino",
              "PE" => "Pescara",
              "PC" => "Piacenza",
              "PI" => "Pisa",
              "PT" => "Pistoia",
              "PN" => "Pordenone",
              "PZ" => "Potenza",
              "PO" => "Prato",
              "RG" => "Ragusa",
              "RA" => "Ravenna",
              "RC" => "Reggio Calabria",
              "RE" => "Reggio Emilia",
              "RI" => "Rieti",
              "RN" => "Rimini",
              "RM" => "Roma",
              "RO" => "Rovigo",
              "SA" => "Salerno",
              "SS" => "Sassari",
              "SV" => "Savona",
              "SI" => "Siena",
              "SR" => "Siracusa",
              "SO" => "Sondrio",
              "TA" => "Taranto",
              "TE" => "Teramo",
              "TR" => "Terni",
              "TO" => "Torino",
              "TP" => "Trapani",
              "TN" => "Trento",
              "TV" => "Treviso",
              "TS" => "Trieste",
              "UD" => "Udine",
              "VA" => "Varese",
              "VE" => "Venezia",
              "VB" => "Verbano-Cusio-Ossola",
              "VC" => "Vercelli",
              "VR" => "Verona",
              "VV" => "Vibo Valentia",
              "VI" => "Vicenza",
              "VT" => "Viterbo");

  /**
   * Class constructor
   *
   * @access   public
   * @param    Net_EPP_IT_Client            client class
   * @param    Net_EPP_IT_StorageInterface  storage class
   */
  public function __construct(&$client, &$storage) {
    $this->client  = $client;
    $this->storage = $storage;
  }

  /**
   * authinfo generator
   *
   * @access   public
   * @return   string[16]  random authinfo code
   */
  public function authinfo() {
    return substr(md5(rand()), 0, 16);
  }

  /**
   * check existence of iso-3166-1 code
   *
   * @access   protected
   * @param    string    iso-3166-1 code
   * @return   boolean   status
   */
  protected function is_iso3166_1($code) {
    return in_array($code, array_keys($this->iso3166_1));
  }

  /**
   * check existence of iso-3166-2:IT code
   *
   * @access   protected
   * @param    string    iso-3166-2:IT code
   * @return   boolean   status
   */
  protected function is_iso3166_2IT($code) {
    return in_array($code, array_keys($this->iso3166_2IT));
  }

  /**
   * check existence of iso-3166-1 code (european union)
   *
   * @access   protected
   * @param    string    iso-3166-1 code
   * @return   boolean   status
   */
  protected function is_iso3166_1EU($code) {
    return in_array($code, array_keys($this->iso3166_1EU));
  }

  /**
   * set error code and message
   *
   * @access   protected
   * @param    string    error message
   * @param    string    4-digit error code
   */
  protected function error($msg, $code = "0000") {
    $this->svMsg = $msg;
    $this->svCode = $code;
  }

  /**
   * execute ever returning queries to the server
   *
   * @access   protected
   * @param    string    client transaction type
   * @param    string    client transaction object
   * @param    boolean   store transaction and response
   * @return   boolean   status
   */
  protected function ExecuteQuery($clTRType, $clTRObject, $store = TRUE) {
    // store request
    if ( $store )
      $this->storage->storeTransaction(
        $this->client->get_clTRID(),
        $clTRType,
        $clTRObject,
        $this->xmlQuery);

    // send request + parse response
    $this->result = $this->client->sendRequest($this->xmlQuery);
    $this->xmlResult = $this->client->parseResponse($this->result['body']);

    // look for a server response code
    if ( is_object($this->xmlResult->response->result) ) {

      // look for a server message
      if ( is_object($this->xmlResult->response->result->msg) )
        $this->svMsg = $this->xmlResult->response->result->msg;
      else
        $this->svMsg = "";

      // look for a server message code
      $this->svCode = $this->xmlResult->response->result['code'];
      switch ( substr($this->svCode, 0, 1) ) {
        case "1":
          $return_code = TRUE;
          break;
        case "2":
        default:
          $return_code = FALSE;
          break;
      }

      // look for an extended server error message and code
      if ( is_object($this->xmlResult->response->result->extValue->reason) ) {
        $this->extValueReasonCode = $this->xmlResult->response->result->extValue->value->reasonCode;
        $this->extValueReason = $this->xmlResult->response->result->extValue->reason;
      } else {
        $this->extValueReasonCode = '';
        $this->extValueReason = '';
      }

    } else {
      $this->error("Unexpected result (no xml response code).");
      $return_code = FALSE;
    }

    // look for a server transaction ID
    if ( isset($this->xmlResult->response->trID->svTRID) && is_object($this->xmlResult->response->trID->svTRID) )
      $this->svTRID = $this->xmlResult->response->trID->svTRID;
    else
      $this->svTRID = "";

    // store response
    if ( $store )
      $this->storage->storeResponse(
        $this->client->get_clTRID(),
        $this->svTRID,
        $this->svCode,
        0,
        $this->result,
        $this->extValueReasonCode,
        $this->extValueReason);

    return $return_code;
  }

}

