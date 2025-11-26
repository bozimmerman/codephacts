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
    'extensions' => ['asm', 's', 'a', 'inc'],
    'language' => 'assembly',
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
            if (strpos($trimmed, ';') === 0) 
            {
                $stats['comment_lines']++;
                continue;
            }
            if (strpos($trimmed, '*') === 0) 
            {
                $stats['comment_lines']++;
                continue;
            }
            if (strpos($trimmed, '#') === 0 && !preg_match('/^#(include|define|ifdef|ifndef|endif)/', $trimmed)) 
            {
                $stats['comment_lines']++;
                continue;
            }
            $stats['code_lines']++;
            $stats['ncloc']++;
            $codePart = $line;
            $commentPos = false;
            $semiPos = strpos($line, ';');
            if ($semiPos !== false)
                $commentPos = $semiPos;
            $hashPos = strpos($line, '#');
            if ($hashPos !== false && !preg_match('/^#(include|define|ifdef)/', trim($line))) 
            {
                if ($commentPos === false || $hashPos < $commentPos)
                    $commentPos = $hashPos;
            }
            if ($commentPos !== false) 
            {
                $stats['comment_lines']++;
                $codePart = substr($line, 0, $commentPos);
            }
            $statements = 0;
            if (preg_match('/^\s*\./', $trimmed))
                $statements = 1;
            elseif (preg_match('/:/', $codePart))
                $statements = 1;
            elseif (preg_match('/^\s*[a-zA-Z]{2,5}\s+/', $trimmed))
                $statements = 1;
            elseif (preg_match('/^\s*#(include|define|ifdef|ifndef|endif)/', $trimmed))
                $statements = 1;
            elseif (preg_match('/^\s*\w+\s+(BYTE|WORD|TEXT|DATA|DCB|DW|DB|DS)\s+/i', $trimmed))
                $statements = 1;
            elseif (preg_match('/^\s*\*\s*=/', $trimmed))
                $statements = 1;
            else
                $statements = 1;
                
            $stats['code_statements'] += $statements;
            $stats['weighted_code_lines'] += 1.0;
            $stats['weighted_code_statements'] += $statements;
        }
    }
];