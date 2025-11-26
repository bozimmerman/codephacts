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
    'extensions' => ['script'],
    'language' => 'mobprog',
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
            if ($trimmed === '~') 
            {
                $stats['blank_lines']++;
                continue;
            }
            $stats['code_lines']++;
            $stats['ncloc']++;
            if (strpos($line, '#') !== false) 
                $stats['comment_lines']++;
            $statements = 1;
            if (preg_match('/^\w+_PROG/', $trimmed))
                $statements = 1;
            $stats['code_statements'] += $statements;
            $stats['weighted_code_lines'] += 1.0;
            $stats['weighted_code_statements'] += $statements;
        }
    }
];