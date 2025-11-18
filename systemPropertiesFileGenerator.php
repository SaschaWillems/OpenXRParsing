<?php

/*
 * Copyright (C) 2025 by Sascha Willems (www.saschawillems.de)
 *
 * This code is free software, you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License version 3 as published by the Free Software Foundation.
 *
 * Please review the following information to ensure the GNU Lesser
 * General Public License version 3 requirements will be met:
 * http://opensource.org/licenses/lgpl-3.0.html
 *
 * The code is distributed WITHOUT ANY WARRANTY; without even the
 * implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR
 * PURPOSE.  See the GNU LGPL 3.0 for more details.
 *
 */

$xml = simplexml_load_file("xr.xml") or exit("Could not read xr.xml");

function findType(string $name) {
    global $xml;
    foreach ($xml->types->type as $type) {
        if (strcasecmp((string)$type['name'], $name) === 0) {
            return $type;
        }
    }
}

function getsType($type) {
    foreach ($type->member as $member) {
        if ($member['values'] && (stripos((string)$member['values'], 'XR_TYPE_') !== false)) {
            return $type->member['values'];
        }
    }
}

$sysprop_exts = [];

foreach ($xml->extensions->extension as $extension) {
    if ((string)$extension['supported'] == "disabled") {
        continue;
    }
    foreach($extension->require->type as $type) {
        $type = findType((string)$type['name']);
        if ($type && (strcasecmp((string)$type['structextends'], 'XrSystemProperties') === 0)) {
            $sysprop_exts[] = $extension;
            break;
        }
    }
}

foreach ($sysprop_exts as $extension) {
    echo (string)$extension['name'].PHP_EOL;
    foreach($extension->require->type as $type) {
        $type = findType((string)$type['name']);
        if ($type && (strcasecmp((string)$type['structextends'], 'XrSystemProperties') === 0)) {
            echo "  ".(string)$type['name'].PHP_EOL;
            $sType = getsType($type);
            echo "    sType = ".$sType.PHP_EOL;
            foreach ($type->member as $type_member) {
                $type = (string)$type_member->type;
                if (in_array($type, ['XrStructureType', 'void'])) {
                    continue;
                }
                echo "      ".(string)$type_member->name. " is of type ".(string)$type_member->type.PHP_EOL;
            }
        }
    }
}

ob_start();

// Todos:
// - Group by vendor, similar to Vk (if possible)
// - Create ext struct on heap due to max. stack size
// - Add (more) type mappings

foreach ($sysprop_exts as $extension) {
    $extension_name = (string)$extension['name'];
    $protected_by = (string)$extension['protect'];
    foreach($extension->require->type as $type) {
        $type = findType((string)$type['name']);
        if ($type && (strcasecmp((string)$type['structextends'], 'XrSystemProperties') === 0)) {
            $type_name = $type['name'];
            $sType = getsType($type);
            assert($sType);

            if ($protected_by !== '') {
                echo "#ifdef $protected_by".PHP_EOL;
            }

            echo "if (extensionSupported(\"$extension_name\")) {".PHP_EOL;
            echo "\t\t$type_name extProps{ .type = $sType };".PHP_EOL;
            echo "\t\tXrSystemProperties systemProps{ .type = XR_TYPE_SYSTEM_PROPERTIES, .next = &extProps };".PHP_EOL;
            echo "\t\tXrResult result = xrGetSystemProperties(instance, systemId, &systemProps);".PHP_EOL;
            echo "\t\tif (XR_SUCCEEDED(result)) {".PHP_EOL;
            foreach ($type->member as $type_member) {
                $type_member_type = (string)$type_member->type;
                if (in_array($type_member_type, ['XrStructureType', 'void'])) {
                    continue;
                }
                $type_member_name = $type_member->name;
                $type_member_conversion = "extProps.$type_member_name";
                switch ($type_member_type) {
                    case 'XrBool32':
                        $type_member_conversion = "bool(extProps.$type_member_name)";
                        break;
                    case 'XrFlags64':
                        // uint64_t
                        break;
                    case 'XrTime':
                    case 'XrDuration':
                        // int64_t
                        break;
                    case 'XrVersion':
                        //uint64_t
                        break;
                    case 'XrUuidEXT':
                        $type_member_conversion = "";
                        echo "\t\t\t// Warning: No support for property $type_member_name with type $type_member_type".PHP_EOL;                        
                        continue;
                }
                if ($type_member_conversion !== '') {
                    echo "\t\t\tpushSytemProperty(\"$extension_name\", \"$type_name\", \"$type_member_name\", $type_member_conversion);".PHP_EOL;
                }
            }
            echo "\t\t}".PHP_EOL;
            echo "}".PHP_EOL;

            if ($protected_by !== '') {
                echo "#endif".PHP_EOL;
            }

            echo PHP_EOL;
        }
    }
}

$res = ob_get_clean();
file_put_contents('xrsystemproperties.cpp', $res);