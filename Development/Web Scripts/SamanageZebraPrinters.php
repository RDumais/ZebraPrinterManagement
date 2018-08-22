<?php

/**
 * Created by PhpStorm.
 * User: ryand
 * Date: 6/1/2018
 * Time: 9:31 AM
 */

//requires external files for the LDAP credentials and Samanage API Token. This allows the credentials to remain hidden from public.
require('samanagetoken.php');

//grab parameters from Syteline form
$currentCount = $_GET['currentCount'];
$previousCount = $_GET['previousCount'];
$printerType = $_GET['printerType'];
$requesterEmail = $_GET['requesterEmail'];
$assigneeEmail = $_GET['assigneeEmail'];
$configurationItem = $_GET['configurationItem'];

//set token variable
$authorization = 'X-Samanage-Authorization: Bearer ' . $token;

//set the comment variable
$comment = "ZebraPrinterRecordData" . PHP_EOL . PHP_EOL . "Previous Count: " . $previousCount . " IN" . PHP_EOL . "Current Count: " . $currentCount . " IN";

//set the url based on the printer's DPI type, the resultset is filtered by the URL end parameters
if ($printerType == 300) {
    $getTicketUrl = 'https://api.samanage.com/incidents.json?title=*Zebra%20Printhead%20Monitor:PayLoad=Print*18000*';
} else if ($printerType == 600) {
    $getTicketUrl = 'https://api.samanage.com/incidents.json?title=*Zebra%20Printhead%20Monitor:PayLoad=Print*6000*';
}

//initiate curl request to retrieve latest and open Zebra ticket
$getZebraTicketCurl = curl_init($getTicketUrl);

//set curl options
curl_setopt($getZebraTicketCurl, CURLOPT_HTTPHEADER, array('Accept: application/vnd.samanage.v2.1+json', $authorization));
curl_setopt($getZebraTicketCurl, CURLOPT_CUSTOMREQUEST, "GET");
curl_setopt($getZebraTicketCurl, CURLOPT_FAILONERROR, true);
curl_setopt($getZebraTicketCurl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($getZebraTicketCurl, CURLOPT_FOLLOWLOCATION, 1);

//raw results of response
$result = curl_exec($getZebraTicketCurl);

//was first curl successful? if so continue, if not then explain error
if (curl_error($getZebraTicketCurl)) {
    echo 'Failed on 1st cURL';
    echo 'cURL Error:' . curl_error($getZebraTicketCurl);
} else { //let's continue

    //turn raw response into json format
    $jsonResult = json_decode($result);


    //get id of new or assigned Zebra ticket.
    for ($i = 0; $i <= count($jsonResult); $i++) {
        if ($jsonResult[$i]->state == 'New' || $jsonResult[$i]->state == 'Assigned') {
            $zebraID = $jsonResult[$i]->id;
            break;
        }
    }

    //xml data for upcoming curl request, setting the requester and assignee and attaching the appropriate printer
    $requester_xml = '<incident>
                            <requester>
                                <email>' . $requesterEmail . '</email>
                            </requester>
                            <assignee>
                                 <email>' . $assigneeEmail . '</email>
                            </assignee>
                            <configuration_item_ids type="array">
                                 <configuration_item_id>' . $configurationItem . '</configuration_item_id>
                            </configuration_item_ids>
                          </incident>';
    {"change": {"name": "Install APAR {{APAR Number}}","state": "Open","description": "{{APAR Description}}","change_plan": "<ol><li>Backup any affected production objects, particularly site versions.<li>Deploy the change to Development<li>Merge Old Vendor, New Vendor, and Site together.<li>Vendorize the resulting merges.<li>Script out the merged files<li>Deploy the change to Pilot<li>Deploy any merged files.<li>Test according to the Test Plan.<li>Deploy the change to Production.<li>Deploy any merged files.<li>Redeploy/Repair Third Party Products.</ol>","test_plan": "<p>Test {{Form}} for the APAR's Behavior:</p><p>\"{{APAR Impact}}\"</p><p>Test any files that were merged.</p>","custom_fields_values": {"custom_fields_value": [{"name": "Does the change introduce risk?","value": "{{Does the change introduce risk?}}"},{"name": "Has the intended use of the software changed?","value": "{{Has the intended use of the software changed?}}"}]}}}

    //initialize curl request to set QA manage as requester (instead of WUG) and set the IT tech as the assignee
    $putRequesterCurl = curl_init('https://api.samanage.com/incidents/' . $zebraID . '.xml');

    //set curl options
    curl_setopt($putRequesterCurl, CURLOPT_HTTPHEADER, array('Accept: application/vnd.samanage.v2.1+xml', 'Content-Type: text/xml', $authorization));
    curl_setopt($putRequesterCurl, CURLOPT_POSTFIELDS, $requester_xml);
    curl_setopt($putRequesterCurl, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($putRequesterCurl, CURLOPT_FAILONERROR, true);
    curl_setopt($putRequesterCurl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($putRequesterCurl, CURLOPT_FOLLOWLOCATION, 1);
    $result = curl_exec($putRequesterCurl);

    //was second curl successful? if so continue, if not then explain error
    if (curl_error($putRequesterCurl)) {
        echo 'Failed on 2nd cURL. Chances are there are no available tickets in an open state. ';
        echo 'cURL Error: ' . curl_error($putRequesterCurl);
    } else {
        //xml data for upcoming curl request, constructing the comment
        $comment_xml = '<comment>
                            <body>' . $comment . '</body>
                            <is_private>false</is_private>
                          </comment>';

        //initialize curl request to comment the print counts (previous and current) and a keyword to autoclose the ticket in Samanage
        $postZebraCurl = curl_init('https://api.samanage.com/incidents/' . $zebraID . '/comments.xml');

        //set curl options
        curl_setopt($postZebraCurl, CURLOPT_HTTPHEADER, array('Accept: application/xml', 'Content-Type: text/xml', $authorization));
        curl_setopt($postZebraCurl, CURLOPT_POSTFIELDS, $comment_xml);
        curl_setopt($postZebraCurl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($postZebraCurl, CURLOPT_FAILONERROR, true);
        curl_setopt($postZebraCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($postZebraCurl, CURLOPT_FOLLOWLOCATION, 1);
        $result = curl_exec($postZebraCurl);

        //if error in curl, explain error
        if (curl_error($postZebraCurl)) {
            echo 'Failed on 3rd cURL';
            echo 'cURL Error: ' . curl_error($postZebraCurl);
        } else {
            echo 'Success!';
        }
    }
}

//http://lsc-sv-web1/automations/samanage/SamanageZebraPrinters.php?currentCount=0&previousCount=5999&printerType=600&requesterEmail=BLarose@linemaster.com&assigneeEmail=RDumais@linemaster.com&configurationItem=45645646