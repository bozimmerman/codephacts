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
    'extensions' => ['css', 'scss', 'sass', 'less'],
    'language' => 'css',
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
            if (strpos($trimmed, '/*') !== false && strpos($trimmed, '*/') === false)
            {
                $inBlockComment = true;
                $stats['comment_lines']++;
                $stats['ncloc']++;
                continue;
            }
            if ($inBlockComment)
            {
                $stats['comment_lines']++;
                $stats['ncloc']++;
                if (strpos($trimmed, '*/') !== false)
                    $inBlockComment = false;
                continue;
            }
            if (strpos($trimmed, '/*') !== false && strpos($trimmed, '*/') !== false)
            {
                $stats['comment_lines']++;
                $stats['ncloc']++;
                continue;
            }
            $stats['ncloc']++;
            $declarations = substr_count($line, ';');
            $stats['code_statements'] += $declarations;
        }
        return $stats;
    }
];