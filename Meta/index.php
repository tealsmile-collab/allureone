<?php

// ================= CONFIG =================

$verify_token = "allure2thai";
$page_access_token = "EAAWcHEq228oBQZB3DJSw29KWbLMZCZBThjZAiGk3ZA7rvGZCziMlFzSrYbtZBZBsGalEtSDiaRaDPmCnMdDz4NQHZAWrpjK14aTkv03GmxJpHthRHZAU4ucZAZC5ZB3YF7pOZCD1EQ9h5X36aKfivZBoZAgd5MvWIsrAfCdojdZBG5XvIfE4yWqap93p5UAuSsCGl7YBxdTl7WZCmObqsZD";   // current page token
$user_long_lived_token = "EAAWcHEq228oBQZBeV9zdhlGqpTUOKcUpDaswBTOEzhTvKek8rZCqo1VeFQyTfMkaJERJUpz5ZBdIQU69NOiIEXi5SpwjZCcJbrVZBV8QHU15RxMsvgXltSe7cbC1NbbsCvLDHQgjCzNL4r5IxSmsSFfZCEZC5R1HEY7iPdnqQFabAeaZChCREkKqZBVsjmkHaQiYI";

$app_id = "1579020210068426";
$app_secret = "747cdf90c2a2d4141dea882899c97e32";
$page_id = "120822387660626";


$api_url = "https://server.gallabox.com/devapi/messages/whatsapp";
$apiKey = "6943d160bdb748e645cb887e";
$apiSecret = "002bdbfa12fb47ddb5d927bf6dfcc2d5";

$logFile = "lead_activity.log";
$franchiseLogFile = "franchise_leads.log";
$apiLogFile = "api_log.txt";
$rawLeadDataLogFile = "rawLeadData.log";

// Franchise DB (standalone inline config for webhook file)
$frDbHost = "82.25.121.179";
$frDbUser = "u716393246_allureproadmin";
$frDbPass = "allure@Dmin123";
$frDbName = "u716393246_AllurePro";



// ================= SAFE ESCAPE =================

function escapeValue($value){

    if($value === null) return "";

    $value = trim($value);

    $search = ["\\","\"","'","<",">","&","/",":"];
    $replace = ["\\\\","\\\"","\\'","\\<","\\>","\\&","\\/","\\:"];

    return str_replace($search,$replace,$value);
}



// ================= NORMALIZE VALUES =================

function normalizeValue($value){

    if($value === null) return "";

    $value = str_replace("_"," ",$value);
    $value = str_replace("₹","Rs.",$value);
    $value = str_replace("–","-",$value);

    return trim($value);
}

function getLeadFieldValueByName($lead, $targetName){

    if(!isset($lead['field_data']) || !is_array($lead['field_data'])){
        return "";
    }

    $target = strtolower(trim((string)$targetName));

    foreach($lead['field_data'] as $field){
        if(!is_array($field)) continue;
        $name = strtolower(trim((string)($field['name'] ?? '')));
        if($name !== $target) continue;

        $values = $field['values'] ?? null;
        if(is_array($values) && isset($values[0])){
            return (string)$values[0];
        }
        return "";
    }

    return "";
}



// ================= BRANCH + FORM MAPS =================
// Rule: many form_ids → one branch; one form_id → exactly one branch (never multiple branches).

$branchConfig = [
    "andheri_east_marol" => ["phone" => "917304455836", "branch_id" => 3000],
    "malad" => ["phone" => "919920309399", "branch_id" => 4185],
    "andheri_west_lokhandwala" => ["phone" => "917777049450", "branch_id" => 4507],
    "borivali" => ["phone" => "918624020816", "branch_id" => 2973],
    "powai" => ["phone" => "918652020816", "branch_id" => 2935],
    "mulund" => ["phone" => "918080515738", "branch_id" => 3781],
    "thane" => ["phone" => "919987799720", "branch_id" => 3780],
    "navi_mumbai_-_seawoods" => ["phone" => "919324525471", "branch_id" => 3782],
    "navi_mumbai_-_kharghar" => ["phone" => "918424925346", "branch_id" => 5000],
    "palghar" => ["phone" => "917875588844", "branch_id" => 5001],
    "boisar" => ["phone" => "919325825052", "branch_id" => 4456],
    "gujrat_-_halol_vadodara" => ["phone" => "919274954980", "branch_id" => 5002],
    "ratnagiri" => ["phone" => "918983188738", "branch_id" => 4274],
    "thanevartaknagar" => ["phone" => "919321852726", "branch_id" => 4651],
];

// form_id => branch key (add more form ids under the same branch key as needed)
$formIdToBranchKey = [
    "1069208812451626" => "navi_mumbai_-_seawoods",
    "957571290636446" => "boisar",
    "1000356342810563" => "thanevartaknagar",
    "1036833275598874" => "thanevartaknagar",
    "2487128015124199" => "andheri_west_lokhandwala",
    // Add each branch's unique Meta form id(s) below, e.g.:
    // "FORM_ID_1" => "andheri_east_marol",
    // "FORM_ID_2" => "andheri_east_marol",
];

// Derived lookups (kept for location-name matching when lead has location/branch field)
$branchPhones = [];
$branchNameToBranchId = [];
foreach ($branchConfig as $branchKey => $cfg) {
    $branchPhones[$branchKey] = (string) ($cfg["phone"] ?? "");
    $branchNameToBranchId[$branchKey] = (int) ($cfg["branch_id"] ?? 0);
}
// Alias used in older maps
$branchNameToBranchId["vartaknagar"] = (int) ($branchConfig["thanevartaknagar"]["branch_id"] ?? 4651);

function meta_branch_key_for_form_id($formId, array $formIdToBranchKey): string
{
    $formId = trim((string) $formId);
    if ($formId === "" || !isset($formIdToBranchKey[$formId])) {
        return "";
    }

    return strtolower(trim((string) $formIdToBranchKey[$formId]));
}

function meta_branch_info_for_key($branchKey, array $branchConfig): ?array
{
    $branchKey = strtolower(trim((string) $branchKey));
    if ($branchKey === "" || !isset($branchConfig[$branchKey])) {
        return null;
    }
    $cfg = $branchConfig[$branchKey];

    return [
        "key" => $branchKey,
        "phone" => (string) ($cfg["phone"] ?? ""),
        "branch_id" => (int) ($cfg["branch_id"] ?? 0),
        "label" => trim(str_replace("_", " ", $branchKey)),
    ];
}


// ================= WEBHOOK VERIFICATION =================

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';

    if ($mode === 'subscribe' && $token === $verify_token) {
        echo $challenge;
        exit;
    }

    http_response_code(403);
    exit;
}



// ================= RECEIVE WEBHOOK =================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    global $branchConfig,$formIdToBranchKey,$branchPhones,$branchNameToBranchId,$page_access_token,$api_url,$apiKey,$apiSecret,$logFile,$franchiseLogFile,$apiLogFile;

    $input = file_get_contents("php://input");
    $data = json_decode($input,true);

    $rawLeadLog  = "\n=============================\n";
    $rawLeadLog .= "Time: ".date("Y-m-d H:i:s")."\n";
    $rawLeadLog .= ($input !== false && $input !== "" ? $input : "[empty body]")."\n";
    file_put_contents($rawLeadDataLogFile, $rawLeadLog, FILE_APPEND);


    if(isset($data['entry'][0]['changes'][0]['value']['leadgen_id'])){

        $lead_id = $data['entry'][0]['changes'][0]['value']['leadgen_id'];
        $form_id = $data['entry'][0]['changes'][0]['value']['form_id'] ?? "";


        // ================= FETCH FULL LEAD DATA =================

        $url = "https://graph.facebook.com/v19.0/".$lead_id."?access_token=".$page_access_token;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $lead_response = curl_exec($ch);
        curl_close($ch);

        $lead = json_decode($lead_response,true);


        $customerName="";
        $phoneNumber="";
        $preferredLocation="";
        $details="";
        $recipientName="Shailesh";
        $recipientPhone="918369676845";
        $sourceName="";
        $isMothersDayLead = false;
        $mothersDayBranchId = null;



        // ================= FRANCHISE FORM =================

        if($form_id == "1619117882846016"){

            $sourceName = "Meta Lead - Franchise";

            foreach($lead['field_data'] as $field){

                $fieldName = strtolower($field['name']);
                $fieldName = str_replace(" ","_",$fieldName);
                $fieldName = str_replace([":","?"],"",$fieldName);

                $value = $field['values'][0];

                if($fieldName == "full_name"){
                    $customerName = escapeValue(normalizeValue($value));
                    continue;
                }

                if($fieldName == "phone_number"){
                    $phoneNumber = escapeValue(normalizeValue($value));
                    continue;
                }

                $label = str_replace("_"," ",$fieldName);

                $details .= $label." - ".escapeValue(normalizeValue($value))."\n";
            }


            // ===== LOG RAW FRANCHISE JSON =====

            $logEntry  = "\n=============================\n";
            $logEntry .= "Time: ".date("Y-m-d H:i:s")."\n";
            $logEntry .= "Lead ID: ".$lead_id."\n";
            $logEntry .= $lead_response."\n";

            file_put_contents($franchiseLogFile,$logEntry,FILE_APPEND);

            // ===== SAVE FRANCHISE LEAD INTO DB =====
            $dbFullName = normalizeValue(getLeadFieldValueByName($lead, "full_name"));
            $dbPhone = normalizeValue(getLeadFieldValueByName($lead, "phone_number"));
            $dbCity = normalizeValue(getLeadFieldValueByName($lead, "city"));
            $dbInvestment = normalizeValue(getLeadFieldValueByName($lead, "select_investment_budget_range:"));
            $dbTimeline = normalizeValue(getLeadFieldValueByName($lead, "what_is_your_preferred_timeline_to_start_operations?"));
            $dbExperience = normalizeValue(getLeadFieldValueByName($lead, "do_you_have_prior_experience_in_the_wellness_or_beauty_industry?"));
            $dbProperty = normalizeValue(getLeadFieldValueByName($lead, "do_you_currently_possess_a_property_for_the_wellness_centre?"));
            $dbSource = "Meta";
            $dbFormId = trim((string)($lead['id'] ?? ''));

            $dbDateTime = date("Y-m-d H:i:s");
            $createdTimeRaw = trim((string)($lead['created_time'] ?? ''));
            if($createdTimeRaw !== ''){
                $ts = strtotime($createdTimeRaw);
                if($ts !== false){
                    $dbDateTime = date("Y-m-d H:i:s", $ts);
                }
            }

            try{
                $dsn = "mysql:host=".$frDbHost.";dbname=".$frDbName.";charset=utf8";
                $pdo = new PDO($dsn, $frDbUser, $frDbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                $sql = "INSERT INTO allureone_franchise_leads
                    (FULL_NAME, PHONE_NUMBER, CITY, investment_budget, preferred_timeline, experience_in_the_wellness, property_for_the_wellness, sourceName, DateTime, form_id, campaign_id)
                    VALUES
                    (:full_name, :phone_number, :city, :investment_budget, :preferred_timeline, :experience, :property, :source_name, :date_time, :form_id, :campaign_id)";
                $st = $pdo->prepare($sql);
                $st->execute([
                    'full_name' => $dbFullName,
                    'phone_number' => $dbPhone,
                    'city' => $dbCity,
                    'investment_budget' => $dbInvestment,
                    'preferred_timeline' => $dbTimeline,
                    'experience' => $dbExperience,
                    'property' => $dbProperty,
                    'source_name' => $dbSource,
                    'date_time' => $dbDateTime,
                    'form_id' => $dbFormId,
                    'campaign_id' => null,
                ]);
            }catch(Throwable $e){
                $dbLog = date("Y-m-d H:i:s")." | DB Insert Error | ".$lead_id." | ".$e->getMessage()."\n";
                file_put_contents($apiLogFile, $dbLog, FILE_APPEND);
            }
        }



        // ================= SPA / BRANCH FORM =================

        else{

            $sourceName = "Meta Insta-Fb Lead";
            $isMothersDayLead = true;
            $locationFieldFound = false;
            $branchFieldFound = false;

            foreach($lead['field_data'] as $field){

                $fieldName=$field['name'];
                $value=$field['values'][0];

                if($fieldName=="inbox_url") continue;

                if($fieldName=="full_name"){
                    $customerName = escapeValue(normalizeValue($value));
                }

                if($fieldName=="phone_number"){
                    $phoneNumber = escapeValue(normalizeValue($value));
                }

                if(stripos($fieldName, "location") !== false){
                    $preferredLocation = escapeValue(normalizeValue($value));
                    $locationFieldFound = true;
                } elseif(!$locationFieldFound && stripos($fieldName, "branch") !== false){
                    $preferredLocation = escapeValue(normalizeValue($value));
                    $branchFieldFound = true;
                }
            }

            // No location/branch field: resolve from form_id → branch key (many forms may share one branch).
            $mappedBranchKey = "";
            if(!$locationFieldFound && !$branchFieldFound){
                $mappedBranchKey = meta_branch_key_for_form_id($form_id, $formIdToBranchKey);
                $mappedInfo = meta_branch_info_for_key($mappedBranchKey, $branchConfig);
                if(is_array($mappedInfo)){
                    $preferredLocation = escapeValue(normalizeValue($mappedInfo["label"]));
                }
            }

            $locationKey = strtolower(trim(str_replace(" ", "_", $preferredLocation)));

            if(isset($branchPhones[$locationKey])){
                $recipientName = $preferredLocation;
                $recipientPhone = $branchPhones[$locationKey];
            }

            if($mappedBranchKey !== ""){
                $mappedInfo = meta_branch_info_for_key($mappedBranchKey, $branchConfig);
                if(is_array($mappedInfo) && (int)$mappedInfo["branch_id"] > 0){
                    $mothersDayBranchId = (int)$mappedInfo["branch_id"];
                    if($mappedInfo["phone"] !== ""){
                        $recipientName = $preferredLocation !== "" ? $preferredLocation : $mappedInfo["label"];
                        $recipientPhone = $mappedInfo["phone"];
                    }
                }
            } elseif(isset($branchNameToBranchId[$locationKey])){
                $mothersDayBranchId = (int)$branchNameToBranchId[$locationKey];
            }

            $details = "Preferred Location - ".$preferredLocation;
        }



        // ================= CLEAN PHONE =================

        $phoneNumber = preg_replace('/[^0-9]/','',$phoneNumber);

        if(strlen($phoneNumber)==10){
            $phoneNumber="91".$phoneNumber;
        }

        // ================= SAVE META MOTHERS DAY LEAD =================
        if($isMothersDayLead){
            try{
                $dsnMeta = "mysql:host=".$frDbHost.";dbname=".$frDbName.";charset=utf8";
                $pdoMeta = new PDO($dsnMeta, $frDbUser, $frDbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                $sqlMeta = "INSERT INTO allureone_meta_leads
                    (sourceName, Campaiign, branch_id, branch_name, lead_name, lead_phone_number, Created_Datetime, status, remarks, amount, leadgen_id, form_id)
                    VALUES
                    (:sourceName, :campaign, :branch_id, :branch_name, :lead_name, :lead_phone_number, NOW(), :status, :remarks, :amount, :leadgen_id, :form_id)";
                $stMeta = $pdoMeta->prepare($sqlMeta);
                $stMeta->execute([
                    'sourceName' => 'Insta-Fb',
                    'campaign' => 'Meta Campaign ' . $form_id,
                    'branch_id' => $mothersDayBranchId,
                    'branch_name' => $preferredLocation,
                    'lead_name' => $customerName,
                    'lead_phone_number' => $phoneNumber,
                    'status' => 1,
                    'remarks' => null,
                    'amount' => null,
                    'leadgen_id' => (string) $lead_id,
                    'form_id' => (string) $form_id,
                ]);
            }catch(Throwable $e){
                $metaDbLog = date("Y-m-d H:i:s")." | MetaLeads DB Insert Error | ".$lead_id." | ".$e->getMessage()."\n";
                file_put_contents($apiLogFile, $metaDbLog, FILE_APPEND);
            }
        }



        // ================= API PAYLOAD =================

        $payload=[

            "channelId"=>"68ad971bb42a9aef088df331",
            "channelType"=>"whatsapp",

            "recipient"=>[
                "name"=>$recipientName,
                "phone"=>$recipientPhone
            ],

            "whatsapp"=>[
                "type"=>"template",
                "template"=>[
                    "templateName"=>"meta_lead",
                    "bodyValues"=>[
                        "sourceName"=>$sourceName,
                        "customerNumber"=>$phoneNumber,
                        "customerName"=>$customerName,
                        "details"=>trim($details)
                    ]
                ]
            ]
        ];



        // ================= SEND API =================

        $ch = curl_init($api_url);

        curl_setopt($ch, CURLOPT_POST, true);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "apiKey: ".$apiKey,
            "apiSecret: ".$apiSecret,
            "Content-Type: application/json"
        ]);

        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $apiResponse = curl_exec($ch);

        $curlError = curl_error($ch);

        curl_close($ch);



        // ================= API LOGGING =================

        $logTime = date("Y-m-d H:i:s");

        $responseData = json_decode($apiResponse, true);
        $status = "";

        if(is_array($responseData) && isset($responseData['status'])){
            $status = strtolower($responseData['status']);
        }

        if($status !== "success"){

            $logEntry  = $logTime." | ".$form_id." | ".$lead_id."\n";
            $logEntry .= "API Response: ".$apiResponse."\n";

            if(!empty($curlError)){
                $logEntry .= "Curl Error: ".$curlError."\n";
            }

            $logEntry .= "Payload: ".json_encode($payload)."\n";
            $logEntry .= "-----------------------------------\n";

            file_put_contents($apiLogFile,$logEntry,FILE_APPEND);
        }



        // ================= GENERAL LEAD LOG =================

        if(file_exists($logFile)){

            $fileAge = time() - filemtime($logFile);

            if($fileAge > (5 * 24 * 60 * 60)){
                file_put_contents($logFile,"");
            }
        }

        $leadLog = date("Y-m-d H:i:s")." | ".$lead_id." | ".$customerName." | ".$preferredLocation."\n";

        file_put_contents($logFile,$leadLog,FILE_APPEND);

    }

    http_response_code(200);
    echo "EVENT_RECEIVED";

}
?>