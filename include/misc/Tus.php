<?php

namespace Freegle\Iznik;

class Tus {
    public static function upload($url, $mime = "image/webp", $data = NULL) {
        # Basic uploader, which we use for migration.
        $data = $data ? $data : file_get_contents($url);

        $url = TUS_UPLOADER;
        $chkAlgo = "crc32";
        $fileChk = base64_encode(hash($chkAlgo, $data, true));
        $fileLen = strlen($data);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $headers = [
            "Tus-Resumable" => "1.0.0",
            "Content-Type" => "application/offset+octet-stream",
            "Upload-Length" => $fileLen,
            "Upload-Metadata" => "relativePath bnVsbA==,name " . base64_encode($mime) . ",type " . base64_encode("image/webp") . ",filetype " . base64_encode("image/webp") . ",filename " . base64_encode('image.webp')
        ];
        $fheaders = [];
        foreach ($headers as $key => $value) {
            $fheaders[] = $key . ": " . $value;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $fheaders);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo "Error: " . curl_error($ch);
            return NULL;
        }
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 201) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $header = substr($result, 0, $headerSize);
            $headers = Tus::getHeaders($header);
            $url = $headers['Location'];
        } else {
            print "Error creating file \n";
            return NULL;
        }
        curl_close($ch);

        // Get file offset/size on the server
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "HEAD");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $headers = [
            "Tus-Resumable" => "1.0.0",
            "Content-Type" => "application/offset+octet-stream",
        ];
        $fheaders = [];
        foreach ($headers as $key => $value) {
            $fheaders[] = $key . ": " . $value;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $fheaders);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo "Error: " . curl_error($ch);
            return NULL;
        }

        $headers = explode("\r\n", $result);
        $headers = array_map(function ($item) {
            return explode(": ", $item);
        }, $headers);
        $header = array_filter($headers, function ($value) {
            if ($value[0] === "Upload-Offset") {
                return true;
            }
        });
        $serverOffset = (int)array_shift($header)[1];
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
        }

        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 404) {
            print "File not found!";
            return NULL;
        }
        curl_close($ch);

        // Upload whole file
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $headers = [
            "Content-Type" => "application/offset+octet-stream",
            "Tus-Resumable" => "1.0.0",
            "Upload-Offset" => 0,
            "Upload-Checksum" => "$chkAlgo $fileChk",
        ];
        $fheaders = [];
        foreach ($headers as $key => $value) {
            $fheaders[] = $key . ": " . $value;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $fheaders);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo "Error: " . curl_error($ch);
        }
        $rc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if  ($rc == 200 || $rc == 204) {
            return $url;
        } else {
            return NULL;
        }
    }

    public static function getHeaders($respHeaders) {
        $headers = array();

        $headerText = substr($respHeaders, 0, strpos($respHeaders, "\r\n\r\n"));

        foreach (explode("\r\n", $headerText) as $i => $line) {
            if ($i === 0) {
                $headers['http_code'] = $line;
            } else {
                list ($key, $value) = explode(': ', $line);

                $headers[$key] = $value;
            }
        }

        return $headers;
    }
}