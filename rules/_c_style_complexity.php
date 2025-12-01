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

if (!function_exists('analyzeCStyleComplexity'))
{
    function analyzeCStyleComplexity($lines)
    {
        $cyclomatic = 1; // Base complexity starts at 1
        $cognitive = 0;
        $nestingLevel = 0;
        $braceStack = [];

        foreach ($lines as $line) 
        {
            $cleaned = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '""', $line);
            $cleaned = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "''", $cleaned);
            $openBraces = substr_count($cleaned, '{');
            $closeBraces = substr_count($cleaned, '}');
            $decisionPoints = 0;
            if (preg_match_all('/\bif\s*\(/', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\belse\s+if\s*\(/', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bfor\s*\(/', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bwhile\s*\(/', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bdo\s*\{/', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bcase\s+/', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bcatch\s*\(/', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            $andCount = substr_count($cleaned, '&&');
            $orCount = substr_count($cleaned, '||');
            $cyclomatic += $andCount + $orCount;
            $decisionPoints += $andCount + $orCount;
            $ternaryCount = substr_count($cleaned, '?');
            if (preg_match_all('/\?\s*[^>]/', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            $cognitive += $decisionPoints * (1 + $nestingLevel);
            for ($i = 0; $i < $openBraces; $i++)
                $nestingLevel++;
            for ($i = 0; $i < $closeBraces; $i++)
                $nestingLevel = max(0, $nestingLevel - 1);
        }

        return [
            'cyclomatic' => $cyclomatic,
            'cognitive' => $cognitive
        ];
    }
}
