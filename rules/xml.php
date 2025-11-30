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
    'extensions' => ['xml', 'xsl', 'xsd', 'xslt', 'svg', 'plist', 'cmare'],
    'language' => 'xml',
    'analyzer' => function(&$stats, $lines) 
    {
        $WEIGHT = 3.30;
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
                $stats['ncloc']++;
                $matches = [];
                preg_match_all('/<(?!\/)([^>\/]+)(\/?)>/', $line, $matches);
                $statements = count($matches[0]);
                $stats['code_statements'] += $statements;
                $stats['weighted_code_lines'] += $WEIGHT;
                $stats['weighted_code_statements'] += $statements * $WEIGHT;
            }
        }
    }
];