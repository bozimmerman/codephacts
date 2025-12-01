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

if (!function_exists('analyzePythonComplexity'))
{
    function analyzePythonComplexity($lines)
    {
        $cyclomatic = 1;
        $cognitive = 0;
        $previousIndent = 0;
        $nestingLevel = 0;

        foreach ($lines as $line) 
        {
            $cleaned = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '""', $line);
            $cleaned = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "''", $cleaned);

            preg_match('/^(\s*)/', $line, $matches);
            $indent = strlen(str_replace("\t", "    ", $matches[1])) / 4;
            if ($indent > $previousIndent) 
                $nestingLevel++;
            elseif ($indent < $previousIndent) 
            {
                $decrease = ($previousIndent - $indent);
                $nestingLevel = max(0, $nestingLevel - $decrease);
            }
            $previousIndent = $indent;
            $decisionPoints = 0;
            if (preg_match_all('/\bif\s+/', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\belif\s+/', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bfor\s+/', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bwhile\s+/', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bexcept\s*/', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            $andCount = preg_match_all('/\band\b/', $cleaned, $matches);
            $orCount = preg_match_all('/\bor\b/', $cleaned, $matches);
            $cyclomatic += $andCount + $orCount;
            $decisionPoints += $andCount + $orCount;
            if (preg_match_all('/\bif\s+[^\]}\)]+[\]}\)]/', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            $cognitive += $decisionPoints * (1 + $nestingLevel);
        }
        return [
            'cyclomatic' => $cyclomatic,
            'cognitive' => $cognitive
        ];
    }
}
