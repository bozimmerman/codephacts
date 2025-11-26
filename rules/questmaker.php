<?php
/*
 Copyright 2025-2025 Bo Zimmerman
 
 Licensed under the Apache License, Version 2.0 (the "License");
 you may not use this file except in compliance with the License.
 You may obtain a copy of the License at
 
 http://www.apache.org/licenses/LICENSE-2.0
 
 Unless required by applicable law or agreed to in writing, software
 distributed under the License is distributed on an "AS IS" BASIS,
 WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 See the License for the specific language governing permissions and
 limitations under the License.
 */

return [
    'extensions' => ['quest'],
    'language' => 'questmaker',
    'detector' => function($lines) 
    {
        $detections = [];
        $questLines = [];
        $fileGroups = []; // Track multiple files by extension
        $state = 'QUEST'; // States: QUEST, IN_FILE, IN_NAME, IN_DATA
        $currentFileExt = '';
        $currentFileLines = [];
        $nameBuffer = '';
        foreach ($lines as $line) 
        {
            if (preg_match('/<FILE>/', $line)) 
            {
                $state = 'IN_FILE';
                $currentFileExt = '';
                $currentFileLines = [];
            }
            if ($state === 'IN_FILE' && preg_match('/<NAME>/', $line)) 
            {
                $state = 'IN_NAME';
                $nameBuffer = $line;
            }
            if ($state === 'IN_NAME') 
            {
                if ($state !== 'IN_NAME' || $line !== $nameBuffer)
                    $nameBuffer .= $line;
                if (preg_match('/<\/NAME>/', $nameBuffer))
                {
                    $matches = [];
                    if (preg_match('/<NAME>([^<]+)<\/NAME>/s', $nameBuffer, $matches)) 
                    {
                        $fileName = trim($matches[1]);
                        $dotPos = strrpos($fileName, '.');
                        if ($dotPos !== false)
                            $currentFileExt = substr($fileName, $dotPos + 1);
                    }
                    $state = 'IN_FILE';
                }
            }
            if ($state === 'IN_FILE' && preg_match('/<DATA>/', $line)) 
            {
                $state = 'IN_DATA';
                continue; // Don't process this line further
            }
            if (preg_match('/<\/FILE>/', $line)) 
            {
                $state = 'QUEST';
                continue;
            }
            if ($state === 'QUEST')
                $questLines[] = $line;
            elseif ($state === 'IN_DATA')
            {
                if (preg_match('/<\/DATA>/', $line)) 
                {
                    if (count($currentFileLines) > 0 && $currentFileExt !== '') 
                    {
                        if (!isset($fileGroups[$currentFileExt]))
                            $fileGroups[$currentFileExt] = [];
                        $fileGroups[$currentFileExt] = array_merge($fileGroups[$currentFileExt], $currentFileLines);
                    }
                    $currentFileLines = [];
                    $state = 'IN_FILE';
                } 
                else
                    $currentFileLines[] = $line;
            }
        }
        if (count($questLines) > 0)
            $detections[] = ['ext' => 'properties', 'lines' => $questLines];
        foreach ($fileGroups as $ext => $lines) 
        {
            if (count($lines) > 0) 
            {
                if ($ext === 'script')
                    $detections[] = ['ext' => 'script', 'lines' => $lines];
                elseif ($ext === 'xml' || $ext === 'cmare')
                    $detections[] = ['ext' => 'xml', 'lines' => $lines];
            }
        }
        return count($detections) > 1 ? $detections : false;
    },
    'analyzer' => function(&$stats, $lines) 
    {
        foreach ($lines as $line) 
        {
            $trimmed = trim($line);
            if (empty($trimmed)) 
            {
                $stats['blank_lines']++;
                continue;
            }
            if (strpos($trimmed, '#') === 0) 
            {
                $stats['comment_lines']++;
                continue;
            }
            $stats['code_lines']++;
            $stats['code_statements'] += 1;
            $stats['weighted_code_lines'] += 1.0;
            $stats['weighted_code_statements'] += 1.0;
        }
    }
];