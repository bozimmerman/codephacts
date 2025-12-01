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

if (!function_exists('analyzeShellComplexity'))
{
    function analyzeShellComplexity($lines)
    {
        $cyclomatic = 1; // Base complexity starts at 1
        $cognitive = 0;
        $nestingLevel = 0;

        foreach ($lines as $line) 
        {
            $cleaned = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '""', $line);
            $cleaned = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "''", $cleaned);
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
            if (preg_match_all('/\buntil\s+/', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/[^\s]+\)(?!\))/', $cleaned, $matches)) 
            {
                $casePatterns = 0;
                foreach ($matches[0] as $match) 
                {
                    if (!preg_match('/^\(\)/', $match))
                        $casePatterns++;
                }
                if ($casePatterns > 0) 
                {
                    $cyclomatic += $casePatterns;
                    $decisionPoints += $casePatterns;
                }
            }
            $andCount = substr_count($cleaned, '&&');
            $orCount = substr_count($cleaned, '||');
            $cyclomatic += $andCount + $orCount;
            $decisionPoints += $andCount + $orCount;
            if (preg_match('/\b(then|do)\b/', $cleaned))
                $nestingLevel++;
            if (preg_match('/\b(fi|done|esac)\b/', $cleaned))
                $nestingLevel = max(0, $nestingLevel - 1);
            $cognitive += $decisionPoints * (1 + $nestingLevel);
        }

        return [
            'cyclomatic' => $cyclomatic,
            'cognitive' => $cognitive
        ];
    }
}
