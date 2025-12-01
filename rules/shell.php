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

require_once __DIR__ . DIRECTORY_SEPARATOR . '_shell_complexity.php';

return [
    'extensions' => ['sh', 'bash', 'zsh', 'ksh'],
    'language' => 'shell',
    'analyzer' => function(&$stats, $lines)
    {
        $WEIGHT = 2.90;
        $codeLines = [];

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
            $stats['ncloc']++;
            $codePart = $line;
            $hashPos = strpos($line, '#');
            if ($hashPos !== false)
            {
                $beforeHash = substr($line, 0, $hashPos);
                $singleQuotes = substr_count($beforeHash, "'") - substr_count($beforeHash, "\\'");
                $doubleQuotes = substr_count($beforeHash, '"') - substr_count($beforeHash, '\\"');
                if ($singleQuotes % 2 == 0 && $doubleQuotes % 2 == 0)
                {
                    $stats['comment_lines']++;
                    $codePart = $beforeHash;
                }
            }
            $statements = 1;
            $statements += substr_count($codePart, ';');
            $statements += substr_count($codePart, '&') - substr_count($codePart, '&&');
            $code_statements = max(1, $statements);
            $stats['code_statements'] += $code_statements;
            $stats['weighted_code_lines'] += $WEIGHT;
            $stats['weighted_code_statements'] += $code_statements * $WEIGHT;
            $codeLines[] = $line;
        }

        if (!empty($codeLines)) 
        {
            $complexity = analyzeShellComplexity($codeLines);
            $stats['cyclomatic_complexity'] = $complexity['cyclomatic'];
            $stats['cognitive_complexity'] = $complexity['cognitive'];
        }

        return $stats;
    }
];