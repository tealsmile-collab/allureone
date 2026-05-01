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



// ================= BRANCH PHONE MAP =================

$branchPhones = [
"andheri_east" => "917304455836",
"malad_west" => "919920309399",
"borivali" => "918624020816",
"powai" => "918652020816",
"mulund" => "918080515738",
"thane" => "919987799720",
"navi_mumbai_-_seawoods" => "919324525471",
"navi_mumbai_-_kharghar" => "918424925346",
"palghar" => "917875588844",
"boisar" => "919325825052",
"gujrat_-_vadodara" => "919274954980",
"ratnagiri" => "918983188738"
];



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

    global $branchPhones,$page_access_token,$api_url,$apiKey,$apiSecret,$logFile,$franchiseLogFile,$apiLogFile;

    $input = file_get_contents("php://input");
    $data = json_decode($input,true);


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
        }



        // ================= SPA / BRANCH FORM =================

        else{

            $sourceName = "Meta Lead - March For Her";

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

                if($fieldName=="preferred_branch_location"){
                    $preferredLocation = escapeValue(normalizeValue($value));
                }
            }

            $locationKey = strtolower(str_replace(" ","_",$preferredLocation));

            if($form_id == "925633326718664" && isset($branchPhones[$locationKey])){
                $recipientName = $preferredLocation;
                $recipientPhone = $branchPhones[$locationKey];
            }

            $details = "Preferred Branch Location - ".$preferredLocation;
        }



        // ================= CLEAN PHONE =================

        $phoneNumber = preg_replace('/[^0-9]/','',$phoneNumber);

        if(strlen($phoneNumber)==10){
            $phoneNumber="91".$phoneNumber;
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