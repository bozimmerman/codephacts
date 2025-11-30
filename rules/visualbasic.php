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
    'extensions' => ['vb', 'vbs', 'frm', 'cls'],
    'language' => 'visualbasic',
    'analyzer' => function(&$stats, $lines) 
    {
        $WEIGHT = 2.20;
        foreach ($lines as $line) 
        {
            $trimmed = trim($line);
            if (empty($trimmed)) 
            {
                $stats['blank_lines']++;
                continue;
            }
            if (strpos($trimmed, "'") === 0) 
            {
                $stats['comment_lines']++;
                continue;
            }
            $stats['code_lines']++;
            $stats['ncloc']++;
            $codePart = $line;
            $inString = false;
            for ($i = 0; $i < strlen($line); $i++) 
            {
                if ($line[$i] === '"')
                    $inString = !$inString;
                elseif ($line[$i] === "'" && !$inString)
                {
                    $stats['comment_lines']++;
                    $codePart = substr($line, 0, $i);
                    break;
                }
            }
            $statements = 1;
            $cleaned = preg_replace('/"[^"]*"/', '""', $codePart);
            $statements += substr_count($cleaned, ':');
            $stats['code_statements'] += $statements;
            $stats['weighted_code_lines'] += $WEIGHT;
            $stats['weighted_code_statements'] += $statements * $WEIGHT;
        }
    }
];