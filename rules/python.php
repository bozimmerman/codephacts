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

require_once __DIR__ . DIRECTORY_SEPARATOR . '_python_complexity.php';

return [
    'extensions' => ['py', 'pyw'],
    'language' => 'python',
    'analyzer' => function(&$stats, $lines)
    {
        $WEIGHT = 3.10;
        $inBlockComment = false;
        $codeLines = [];

        foreach ($lines as $line)
        {
            $trimmed = trim($line);
            if (empty($trimmed))
            {
                $stats['blank_lines']++;
                continue;
            }
            if (preg_match('/^(\'\'\'|""")/', $trimmed))
            {
                $inBlockComment = !$inBlockComment;
                $stats['comment_lines']++;
                continue;
            }
            if ($inBlockComment)
            {
                $stats['comment_lines']++;
                if (preg_match('/(\'\'\'|""")$/', $trimmed))
                    $inBlockComment = false;
                continue;
            }
            if (strpos($trimmed, '#') === 0)
            {
                $stats['comment_lines']++;
                continue;
            }
            $stats['code_lines']++;
            $stats['ncloc']++;
            $codePart = $line;
            $hashPos = strpos($line, '#');
            if ($hashPos !== false)
            {
                $stats['comment_lines']++;
                $codePart = substr($line, 0, $hashPos);
            }
            $statements = substr_count($codePart, ';') + 1; // +1 for the line itself
            $stats['code_statements'] += $statements;
            $stats['weighted_code_lines'] += $WEIGHT;
            $stats['weighted_code_statements'] += $statements * $WEIGHT;
            $codeLines[] = $line;
        }
        if (!empty($codeLines)) 
        {
            $complexity = analyzePythonComplexity($codeLines);
            $stats['cyclomatic_complexity'] = $complexity['cyclomatic'];
            $stats['cognitive_complexity'] = $complexity['cognitive'];
        }

        return $stats;
    }
];