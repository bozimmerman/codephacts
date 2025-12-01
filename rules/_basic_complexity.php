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

if (!function_exists('analyzeBasicComplexity'))
{
    function analyzeBasicComplexity($lines)
    {
        $cyclomatic = 1;
        $cognitive = 0;
        $nestingLevel = 0;
        foreach ($lines as $line) 
        {
            $cleaned = preg_replace('/"[^"]*"/', '""', $line);
            $decisionPoints = 0;
            if (preg_match_all('/\bIF\b/i', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bFOR\b/i', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bWHILE\b/i', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bDO\b/i', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bCASE\s+/i', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bGOSUB\b/i', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            $andCount = preg_match_all('/\bAND\b/i', $cleaned, $matches);
            $orCount = preg_match_all('/\bOR\b/i', $cleaned, $matches);
            $cyclomatic += $andCount + $orCount;
            $decisionPoints += $andCount + $orCount;
            if (preg_match('/\b(THEN|FOR|WHILE|DO|SELECT)\b/i', $cleaned))
                $nestingLevel++;
            if (preg_match('/\b(ENDIF|END\s+IF|NEXT|WEND|LOOP|END\s+SELECT)\b/i', $cleaned))
                $nestingLevel = max(0, $nestingLevel - 1);
            $cognitive += $decisionPoints * (1 + $nestingLevel);
        }
        return [
            'cyclomatic' => $cyclomatic,
            'cognitive' => $cognitive
        ];
    }
}
