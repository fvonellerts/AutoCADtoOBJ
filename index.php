<html>
<body>
<h1><a href="/">Convert AutoCAD to 3D .obj</a></h1>

<?php

// config
$clientID = "";
$clientSecret = "";
// end config

/**
 * Get all values from specific key in a multidimensional array
 *
 * @param $key string
 * @param $arr array
 * @return null|string|array
 */
function array_value_recursive($key, array $arr)
{
    $val = array();
    array_walk_recursive($arr, function ($v, $k) use ($key, &$val) {
        if ($k == $key) {
            array_push($val, $v);
        }
    });
    return count($val) > 1 ? $val : array_pop($val);
}

$warningConvertOvertime = "Convert process takes a long time. Exiting. Try reloading after a while.";
$warningConvertFailed = "The convert failed with the following message: ";
$warningNo3DView = "No 3D view found. Exiting.";

if (!empty($_FILES["file"]) && $_POST["password"] === "company") {

    // get auth token
    $cmd = "curl -v 'https://developer.api.autodesk.com/authentication/v1/authenticate' -X 'POST' -H 'Content-Type: application/x-www-form-urlencoded' -d 'client_id=$clientID&client_secret=$clientSecret&grant_type=client_credentials&scope=bucket:create%20bucket:read%20data:write%20data:read'";
    exec($cmd, $resultAuth);
    $token = json_decode($resultAuth[0], true)["access_token"];

    // create bucket
    $cmd = "curl -v 'https://developer.api.autodesk.com/oss/v2/buckets' -X 'POST' -H 'Content-Type: application/json' -H 'Authorization: Bearer $token' -d '{\"bucketKey\":\"dwgconverter\",\"policyKey\":\"transient\"}'";
    exec($cmd, $resultBucket);

    // upload to bucket
    $filePath = $_FILES["file"]["tmp_name"];
    $fileLength = filesize($filePath);
    $fileName = basename($_FILES["file"]["name"]);
    $cmd = "curl -v 'https://developer.api.autodesk.com/oss/v2/buckets/dwgconverter/objects/$fileName' -X 'PUT' -H 'Authorization: Bearer $token' -H 'Content-Type: application/octet-stream' -H 'Content-Length: $fileLength' -T '$filePath'";
    exec($cmd, $resultUpload);
    $urn = json_decode(implode("", $resultUpload), true)["objectId"];
    $urnBase64 = base64_encode($urn);

    // convert file
    $cmd = "curl -X 'POST' -H 'Authorization: Bearer $token' -H 'Content-Type: application/json' -v 'https://developer.api.autodesk.com/modelderivative/v2/designdata/job' -d '{\"input\": {\"urn\": \"$urnBase64\"},\"output\": {\"formats\": [{\"type\": \"svf\", \"views\": [\"3d\"]}]}}'";
    exec($cmd, $resultConvert);

    // wait for convert success
    $cmd = "curl -X 'GET' -H 'Authorization: Bearer $token' -v 'https://developer.api.autodesk.com/modelderivative/v2/designdata/$urnBase64/manifest'";
    $success = 0;
    while ($success !== true && $success < 10) {
        exec($cmd, $resultConvertCheck);
        $status = json_decode(implode("", $resultConvertCheck), true)["status"];

        if ($status === "success") {
            $success = true;
        } else {
            if ($status === "failed") {
                echo $warningConvertFailed;
                print_r(json_decode(implode("", $resultConvertCheck), true)["derivatives"][0]["messages"]);
                die();
            } else {
                sleep(1);
                $success++;
            }
        }
    }

    if ($success !== true) {
        die($warningConvertOvertime);
    }

    // get metadata
    $cmd = "curl -X 'GET' -H 'Authorization: Bearer $token' -v 'https://developer.api.autodesk.com/modelderivative/v2/designdata/$urnBase64/metadata'";
    exec($cmd, $resultMetadata);

    $metadata = json_decode(implode("", $resultMetadata), true)["data"]["metadata"];

    // find 3d view
    $metadata3d = false;
    foreach ($metadata as $data) {
        if ($data['role'] === '3d') {
            $metadata3d = $data;
        }
    }

    if ($metadata3d !== false) {
        $guid = $metadata3d["guid"];

        $cmd = "curl -X 'GET' -H 'Authorization: Bearer $token' -v 'https://developer.api.autodesk.com/modelderivative/v2/designdata/$urnBase64/metadata/$guid'";

        $success = 0;
        while ($success !== true && $success < 10) {
            exec($cmd, $resultObjects);
            $status = json_decode(implode("", $resultObjects), true)["status"];

            if ($status === "success") {
                sleep(1);
                $success++;
            } else {
                $success = true;
            }
        }

        if ($success !== true) {
            die($warningConvertOvertime);
        }

        $objectIDs = implode(", ", array_value_recursive("objectid", json_decode(implode("", $resultObjects), true)));

        $cmd = "curl -X 'POST' -H 'Authorization: Bearer $token' -H 'Content-Type: application/json' -v 'https://developer.api.autodesk.com/modelderivative/v2/designdata/job' -d '{\"input\": {\"urn\":\"$urnBase64\"},\"output\": {\"formats\": [{\"type\": \"obj\", \"advanced\": {\"modelGuid\": \"$guid\", \"objectIds\": [$objectIDs]}}]}}'";
        exec($cmd, $resultObj);

        // wait for convert success
        $cmd = "curl -X 'GET' -H 'Authorization: Bearer $token' -v 'https://developer.api.autodesk.com/modelderivative/v2/designdata/$urnBase64/manifest'";
        $success = 0;
        while ($success !== true && $success < 10) {
            exec($cmd, $resultConvertCheck2);
            $status = json_decode(implode("", $resultConvertCheck2), true)["status"];

            if ($status === "success") {
                $success = true;
            } else {
                if ($status === "failed") {
                    echo $warningConvertFailed;
                    print_r(json_decode(implode("", $resultConvertCheck2), true)["derivatives"][0]["messages"]);
                    die();
                } else {
                    sleep(1);
                    $success++;
                }
            }
        }

        if ($success !== true) {
            die($warningConvertOvertime);
        }

        // get metadata from last convert (obj geometry)
        $objMetadata = json_decode(implode("", $resultConvertCheck2),
            true)["derivatives"][sizeof($resultConvertCheck2)];
        $objUrns = array_value_recursive("urn", $objMetadata);

        // create download commands
        $commands = [];
        foreach ($objUrns as $objUrn) {
            $extension = substr($objUrn, -3);
            if ($extension === 'obj' || $extension === 'mtl' || $extension === 'zip') {
                $urnParts = explode("/", $objUrn);
                $urnName = $urnParts[3];

                $objUrn = urlencode($objUrn);
                $urnBase64 = str_replace("=", "", $urnBase64);
                array_push($commands,
                    "curl -o '$urnName' -X 'GET' -H 'Authorization: Bearer $token' -v 'https://developer.api.autodesk.com/modelderivative/v2/designdata/$urnBase64/manifest/$objUrn'");
            }
        }

        ?>

        <h3>Successfully converted!</h3>

        <p>Run this command in the terminal to download the .obj object, .mtl material and .zip textures:</p>

        <?php
        echo "<p>" . implode(" && ", $commands) . "</p>";
    } else {
        die($warningNo3DView);
    }
} else {
    ?>
    <form action="" method="post" enctype="multipart/form-data">
        <input type="password" name="password" id="password" placeholder="Password (company)"><br><br>
        <input type="file" name="file" id="file"><br><br>
        <input type="submit" value="Convert now">
    </form>

    <?php
}

?>

</body>
</html>