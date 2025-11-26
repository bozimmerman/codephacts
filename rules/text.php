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
    'extensions' => ['txt', 'text', 'md', 'markdown', 'rst', 'seq'],
    'language' => 'text',
    'analyzer' => function(&$stats, $lines) 
    {
        $totalChars = 0;
        foreach ($lines as $line) 
        {
            $trimmed = trim($line);
            if (empty($trimmed)) 
            {
                $stats['blank_lines']++;
                continue;
            }
            $totalChars += strlen($line);
            $sentences = preg_split('/[.!?]+/', $line, -1, PREG_SPLIT_NO_EMPTY);
            $sentenceCount = 0;
            foreach ($sentences as $sentence) 
            {
                if (strlen(trim($sentence)) > 0)
                    $sentenceCount++;
            }
            $stats['code_statements'] += $sentenceCount;
            $stats['weighted_code_statements'] += $sentenceCount;
        }
        $wrappedLines = (int)ceil($totalChars / 80);
        $stats['ncloc'] = $wrappedLines;
        $stats['weighted_code_lines'] = (float)$wrappedLines;
        $stats['comment_lines'] = 0;
    }
];