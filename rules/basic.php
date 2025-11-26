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
    'extensions' => ['bas'],
    'language' => 'basic',
    'detector' => function($lines) 
    {
        // Check if this is traditional BASIC or Visual Basic
        $lineNumberCount = 0;
        $vbKeywordCount = 0;
        $sampleSize = min(20, count($lines));
        for ($i = 0; $i < $sampleSize; $i++) 
        {
            $trimmed = trim($lines[$i]);
            if (empty($trimmed)) continue;
            if (preg_match('/^\d+\s+/', $trimmed))
                $lineNumberCount++;
            if (preg_match('/\b(Sub|Function|Private|Public|Dim\s+\w+\s+As|ByVal|ByRef|End\s+Sub|End\s+Function)\b/i', $trimmed))
                $vbKeywordCount++;
        }
        if ($vbKeywordCount > $lineNumberCount && $vbKeywordCount > 2)
            return ['ext' => 'vb', 'lines' => $lines];
        return false; // Handle as BASIC
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
            if (preg_match('/^\d*\s*REM\b/i', $trimmed)) 
            {
                $stats['comment_lines']++;
                continue;
            }
            $stats['code_lines']++;
            $stats['ncloc']++;
            $codePart = $line;
            if (preg_match('/\bREM\b/i', $line)) 
            {
                $stats['comment_lines']++;
                $pos = stripos($line, 'REM');
                $codePart = substr($line, 0, $pos);
            }
            $statements = 1;
            $cleaned = preg_replace('/"[^"]*"/', '""', $codePart);
            $statements += substr_count($cleaned, ':');
            $stats['code_statements'] += $statements;
            $stats['weighted_code_lines'] += 1.0;
            $stats['weighted_code_statements'] += $statements;
        }
    }
];