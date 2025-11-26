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
    'extensions' => ['html', 'htm', 'cmvp'],
    'language' => 'html',
    'detector' => function($lines) {
        $detections = [];
        $htmlLines = [];
        $jsLines = [];
        $cssLines = [];
        $asmLines = [];

        $inScript = false;
        $inStyle = false;
        $inPre = false;
        
        // First pass: check if this looks like assembly wrapped in <pre>
        $preContent = [];
        $foundPre = false;
        foreach ($lines as $line) 
        {
            if (preg_match('/<pre[^>]*>/i', $line)) 
            {
                $foundPre = true;
                $inPre = true;
                continue;
            }
            if (preg_match('/<\/pre>/i', $line)) 
            {
                $inPre = false;
                continue;
            }
            if ($inPre)
                $preContent[] = $line;
        }
        if ($foundPre && count($preContent) > 10) 
        {
            $asmScore = 0;
            $totalLines = count($preContent);
            foreach ($preContent as $line) 
            {
                $trimmed = trim($line);
                if (empty($trimmed))
                    continue;
                if (strpos($trimmed, ';') === 0) 
                {
                    $asmScore++;
                    continue;
                }
                if (preg_match('/^\./', $trimmed))
                    $asmScore += 2; // Weight directives heavily
                if (preg_match('/^\w+:/', $trimmed)) 
                    $asmScore += 2;
                if (preg_match('/^\d+\$:/', $trimmed))
                    $asmScore += 2;
                if (preg_match('/^\s*[a-z]{2,4}\s+/', $trimmed))
                    $asmScore++;
            }
            if ($asmScore / $totalLines > 0.4) 
            {
                $detections[] = ['ext' => 'asm', 'lines' => $preContent];
                return $detections;
            }
        }
        foreach ($lines as $line) 
        {
            if (preg_match('/<script[^>]*>/i', $line)) 
            {
                $inScript = true;
                $matches = [];
                if (preg_match('/<script[^>]*>(.*?)<\/script>/i', $line, $matches)) 
                {
                    if (!empty(trim($matches[1])))
                        $jsLines[] = $matches[1];
                    $inScript = false;
                }
                continue;
            }
            if ($inScript && preg_match('/<\/script>/i', $line)) 
            {
                $inScript = false;
                continue;
            }
            if (preg_match('/<style[^>]*>/i', $line)) 
            {
                $inStyle = true;
                if (preg_match('/<style[^>]*>(.*?)<\/style>/i', $line, $matches)) 
                {
                    if (!empty(trim($matches[1])))
                        $cssLines[] = $matches[1];
                    $inStyle = false;
                }
                continue;
            }
            if ($inStyle && preg_match('/<\/style>/i', $line)) 
            {
                $inStyle = false;
                continue;
            }
            if ($inScript) 
                $jsLines[] = $line;
            elseif ($inStyle)
                $cssLines[] = $line;
            else
                $htmlLines[] = $line;
        }
        
        if (count($htmlLines) > 0) 
            $detections[] = ['ext' => 'html', 'lines' => $htmlLines];
        if (count($jsLines) > 0)
            $detections[] = ['ext' => 'js', 'lines' => $jsLines];
        if (count($cssLines) > 0)
            $detections[] = ['ext' => 'css', 'lines' => $cssLines];
        return count($detections) > 1 ? $detections : false;
    },
    'analyzer' => function(&$stats, $lines) 
    {
        $inBlockComment = false;
        foreach ($lines as $line) 
        {
            $trimmed = trim($line);
            if (empty($trimmed)) 
            {
                $stats['blank_lines']++;
                continue;
            }
            if ($inBlockComment) 
            {
                $stats['comment_lines']++;
                if (strpos($trimmed, '-->') !== false)
                    $inBlockComment = false;
                continue;
            }
            if (strpos($trimmed, '<!--') !== false) 
            {
                $stats['comment_lines']++;
                if (strpos($trimmed, '-->') === false)
                    $inBlockComment = true;
                continue;
            }
            if (preg_match('/<[^>]+>/', $line)) 
            {
                $stats['code_lines']++;
                $matches = [];
                preg_match_all('/<(?!\/)([^>\/]+)(\/?)>/', $line, $matches);
                $statements = count($matches[0]);
                $stats['code_statements'] += $statements;
                $stats['weighted_code_lines'] += 1.0;
                $stats['weighted_code_statements'] += $statements;
            }
        }
    }
];
