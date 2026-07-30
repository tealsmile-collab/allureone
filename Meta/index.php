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

/**
 * PDO for Allure Pro DB (meta form config + branch + leads).
 */
function meta_db_pdo(string $host, string $user, string $pass, string $name): PDO
{
    $dsn = "mysql:host=".$host.";dbname=".$name.";charset=utf8";

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/**
 * Resolve branch + mobile from allureone_meta_form_config.meta_form_id → allureone_branch.
 *
 * @return array{branch_id:int,campaign_name:string,mobile_number:string,branch_label:string}|null
 */
function meta_resolve_branch_by_form_id(PDO $pdo, string $formId): ?array
{
    $formId = trim($formId);
    if ($formId === "") {
        return null;
    }

    $st = $pdo->prepare(
        'SELECT c.BranchId, c.campaign_name,
                b.mobile_number, b.locality, b.business_name
         FROM allureone_meta_form_config c
         INNER JOIN allureone_branch b ON b.id = c.BranchId
         WHERE c.meta_form_id = :fid AND c.IsActive = 1
         LIMIT 1'
    );
    $st->execute(['fid' => $formId]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return null;
    }

    $branchId = (int) ($row['BranchId'] ?? 0);
    if ($branchId <= 0) {
        return null;
    }

    $locality = trim((string) ($row['locality'] ?? ''));
    $businessName = trim((string) ($row['business_name'] ?? ''));
    $label = $locality !== '' ? $locality : $businessName;

    return [
        'branch_id' => $branchId,
        'campaign_name' => trim((string) ($row['campaign_name'] ?? '')),
        'mobile_number' => trim((string) ($row['mobile_number'] ?? '')),
        'branch_label' => $label,
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

    global $page_access_token,$api_url,$apiKey,$apiSecret,$logFile,$franchiseLogFile,$apiLogFile,$frDbHost,$frDbUser,$frDbPass,$frDbName;

    $input = file_get_contents("php://input");
    $data = json_decode($input,true);

    $rawLeadLog  = "\n=============================\n";
    $rawLeadLog .= "Time: ".date("Y-m-d H:i:s")."\n";
    $rawLeadLog .= ($input !== false && $input !== "" ? $input : "[empty body]")."\n";
    file_put_contents($rawLeadDataLogFile, $rawLeadLog, FILE_APPEND);


    if(isset($data['entry'][0]['changes'][0]['value']['leadgen_id'])){

        $lead_id = $data['entry'][0]['changes'][0]['value']['leadgen_id'];
        $form_id = $data['entry'][0]['changes'][0]['value']['form_id'] ?? "";
        $ad_id = $data['entry'][0]['changes'][0]['value']['ad_id'] ?? "";


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
        $metaCampaignName = "";
        $pdoMeta = null;



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
                $pdo = meta_db_pdo($frDbHost, $frDbUser, $frDbPass, $frDbName);

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

            // form_id → BranchId (allureone_meta_form_config) → mobile_number (allureone_branch)
            try{
                $pdoMeta = meta_db_pdo($frDbHost, $frDbUser, $frDbPass, $frDbName);
                $branchInfo = meta_resolve_branch_by_form_id($pdoMeta, (string) $form_id);
                if(is_array($branchInfo)){
                    $mothersDayBranchId = (int) $branchInfo['branch_id'];
                    $metaCampaignName = (string) ($branchInfo['campaign_name'] ?? '');
                    $branchLabel = (string) ($branchInfo['branch_label'] ?? '');
                    $branchMobile = preg_replace('/\D+/', '', (string) ($branchInfo['mobile_number'] ?? '')) ?? '';

                    if(!$locationFieldFound && !$branchFieldFound && $branchLabel !== ''){
                        $preferredLocation = escapeValue(normalizeValue($branchLabel));
                    }

                    if($branchMobile !== ''){
                        $recipientName = $preferredLocation !== '' ? $preferredLocation : ($branchLabel !== '' ? $branchLabel : $recipientName);
                        $recipientPhone = $branchMobile;
                    }
                } else {
                    $missLog = date("Y-m-d H:i:s")." | Meta form config not found for form_id=".$form_id." | lead=".$lead_id."\n";
                    file_put_contents($apiLogFile, $missLog, FILE_APPEND);
                }
            }catch(Throwable $e){
                $pdoMeta = null;
                $cfgLog = date("Y-m-d H:i:s")." | Meta branch resolve error | ".$lead_id." | ".$e->getMessage()."\n";
                file_put_contents($apiLogFile, $cfgLog, FILE_APPEND);
            }

            $details = "Preferred Location - ".$preferredLocation;
        }



        // ================= CLEAN PHONE =================

        $phoneNumber = preg_replace('/[^0-9]/','',$phoneNumber);

        if(strlen($phoneNumber)==10){
            $phoneNumber="91".$phoneNumber;
        }

        // ================= SAVE META LEAD =================
        if($isMothersDayLead){
            try{
                if($pdoMeta === null){
                    $pdoMeta = meta_db_pdo($frDbHost, $frDbUser, $frDbPass, $frDbName);
                }

                $campaignLabel = $metaCampaignName !== ''
                    ? $metaCampaignName
                    : ('Meta Campaign ' . $form_id);

                $sqlMeta = "INSERT INTO allureone_meta_leads
                    (sourceName, Campaiign, branch_id, branch_name, lead_name, lead_phone_number, Created_Datetime, status, remarks, amount, leadgen_id, form_id, ad_id)
                    VALUES
                    (:sourceName, :campaign, :branch_id, :branch_name, :lead_name, :lead_phone_number, NOW(), :status, :remarks, :amount, :leadgen_id, :form_id, :ad_id)";
                $stMeta = $pdoMeta->prepare($sqlMeta);
                $stMeta->execute([
                    'sourceName' => 'Insta-Fb',
                    'campaign' => $campaignLabel,
                    'branch_id' => $mothersDayBranchId,
                    'branch_name' => $preferredLocation,
                    'lead_name' => $customerName,
                    'lead_phone_number' => $phoneNumber,
                    'status' => 1,
                    'remarks' => null,
                    'amount' => null,
                    'leadgen_id' => (string) $lead_id,
                    'form_id' => (string) $form_id,
                    'ad_id' => (string) $ad_id,
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
