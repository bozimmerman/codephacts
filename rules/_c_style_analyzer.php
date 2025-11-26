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

require_once __DIR__ . DIRECTORY_SEPARATOR . '_c_style_comments.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '_c_style_statements.php';

if (!function_exists('analyzeCStyleLines')) 
{
    function analyzeCStyleLines(&$stats, $lines)
    {
        $commentState = ['inBlockComment' => false];
        foreach ($lines as $line) 
        {
            $trimmed = trim($line);
            if (empty($trimmed)) 
            {
                $stats['blank_lines']++;
                continue;
            }
            $commentInfo = analyzeCStyleComment($line, $commentState);
            if ($commentInfo['has_comment'])
            {
                $stats['comment_lines']++;
                continue;
            }
            $stats['code_lines']++;
            
            $statements = analyzeCStyleStatements($line);
            $stats['code_statements'] += max(1, $statements);
            
            $stats['weighted_code_lines'] += 1.0;
            $stats['weighted_code_statements'] += max(1, $statements);
        }
        
        return $stats;
    }
}
