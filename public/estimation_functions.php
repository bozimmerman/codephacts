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

/**
 * Shared estimation functions for project cost/effort calculations
 * Used by both project.php and languages.php
 */

/**
 * Calculate Basic COCOMO estimates
 * @param int $kloc Thousands of lines of code
 * @param string $mode Project mode: 'organic', 'semi-detached', or 'embedded'
 * @return array Effort (person-months), Time (months), People
 */
function calculateCOCOMO($kloc, $mode = 'semi-detached')
{
    $coefficients = [
        'organic' => ['a' => 2.4, 'b' => 1.05, 'c' => 2.5, 'd' => 0.38],
        'semi-detached' => ['a' => 3.0, 'b' => 1.12, 'c' => 2.5, 'd' => 0.35],
        'embedded' => ['a' => 3.6, 'b' => 1.20, 'c' => 2.5, 'd' => 0.32]
    ];
    
    if (!isset($coefficients[$mode]))
        $mode = 'semi-detached';
        
    $coef = $coefficients[$mode];
    $effort = $coef['a'] * pow($kloc, $coef['b']);
    $time = $coef['c'] * pow($effort, $coef['d']);
    $people = $effort / $time;
    
    return [
        'effort' => $effort,
        'time' => $time,
        'people' => $people,
        'mode' => $mode
    ];
}

/**
 * Calculate COCOMO II estimates (Post-Architecture model)
 * @param int $kloc Thousands of lines of code
 * @param array $scaleFactors Scale factors (PREC, FLEX, RESL, TEAM, PMAT) each 0-5
 * @param array $effortMultipliers 17 cost drivers, each 0.5-2.0
 * @return array Effort and time estimates
 */
function calculateCOCOMO2($kloc, $scaleFactors = null, $effortMultipliers = null)
{
    if ($scaleFactors === null) {
        $scaleFactors = [
            'PREC' => 3.0,  // Precedentedness
            'FLEX' => 3.0,  // Development Flexibility
            'RESL' => 3.0,  // Architecture/Risk Resolution
            'TEAM' => 3.0,  // Team Cohesion
            'PMAT' => 3.0   // Process Maturity
        ];
    }
    $B = 0.91 + 0.01 * array_sum($scaleFactors);
    $A = 2.94;
    $effort = $A * pow($kloc, $B);
    if ($effortMultipliers !== null) 
    {
        foreach ($effortMultipliers as $em) 
            $effort *= $em;
    }
    $C = 3.67;
    $D = 0.28 + 0.2 * ($B - 0.91);
    $time = $C * pow($effort, $D);
    $people = $effort / $time;
    return [
        'effort' => $effort,
        'time' => $time,
        'people' => $people,
        'exponent' => $B
    ];
}

/**
 * Estimate Function Points from LOC
 * Simple conversion based on language
 * @param int $loc Lines of code
 * @param string $language Programming language
 * @return array Function points and estimates
 */
function calculateFunctionPoints($loc, $language = 'PHP')
{
    $locPerFP = [
        'Assembly' => 320,
        'C' => 128,
        'C++' => 55,
        'Java' => 55,
        'JavaScript' => 47,
        'PHP' => 60,
        'Python' => 38,
        'Ruby' => 38,
        'C#' => 55,
        'SQL' => 13,
        'HTML' => 15,
        'CSS' => 30,
        'Default' => 60
    ];
    
    $ratio = isset($locPerFP[$language]) ? $locPerFP[$language] : $locPerFP['Default'];
    $functionPoints = $loc / $ratio;
    $hoursPerFP = 7.5; // median
    $effort = ($functionPoints * $hoursPerFP) / 160; // Convert to person-months
    $time = 2.5 * pow($effort, 0.38);
    $people = $effort / $time;
    return [
        'functionPoints' => $functionPoints,
        'effort' => $effort,
        'time' => $time,
        'people' => $people,
        'language' => $language,
        'locPerFP' => $ratio
    ];
}

/**
 * Calculate SLIM (Putnam) model estimates
 * @param int $loc Lines of code
 * @param int $productivity Productivity parameter (typically 2000-12000)
 * @return array Effort and time estimates
 */
function calculateSLIM($loc, $productivity = 5000)
{
    $kloc = $loc / 1000;
    $effort = 3.0 * pow($kloc, 1.12);
    for ($i = 0; $i < 10; $i++) 
    {
        $time = pow($loc / ($productivity * pow($effort, 1/3)), 0.75);
        $newEffort = pow($loc / ($productivity * pow($time, 4/3)), 3);
        if (abs($newEffort - $effort) < 0.01) 
        {
            $effort = $newEffort;
            break;
        }
        $effort = ($effort + $newEffort) / 2;
    }
    $time = pow($loc / ($productivity * pow($effort, 1/3)), 0.75);
    $people = $effort / $time;
    return [
        'effort' => $effort,
        'time' => $time,
        'people' => $people,
        'productivity' => $productivity
    ];
}

/**
 * Calculate Putnam model estimates (alternative formulation)
 * @param int $loc Lines of code
 * @param float $technology Technology constant (2000-12000, default 8000)
 * @return array Effort and time estimates
 */
function calculatePutnam($loc, $technology = 8000)
{
    $kloc = $loc / 1000;
    $k1 = 0.15;
    $k2 = 0.4;
    $tMin = $k1 * pow($kloc, $k2);
    $time = $tMin * 1.2;
    $effort = pow($kloc / ($technology / 1000 * pow($time, 4/3)), 3);
    $timeMonths = $time * 12;
    $effortMonths = $effort * 12;
    $people = $effortMonths / $timeMonths;
    return [
        'effort' => $effortMonths,
        'time' => $timeMonths,
        'people' => $people,
        'technology' => $technology,
        'minTime' => $tMin * 12
    ];
}

/**
 * Generate comprehensive project estimates
 * @param int $loc Lines of code
 * @param string $primaryLanguage Primary programming language
 * @param string $cocomoMode COCOMO mode
 * @return array All estimation results
 */
function generateEstimates($loc, $primaryLanguage = 'PHP', $cocomoMode = 'semi-detached')
{
    $kloc = $loc / 1000;
    
    return [
        'cocomo' => calculateCOCOMO($kloc, $cocomoMode),
        'cocomo2' => calculateCOCOMO2($kloc),
        'functionPoints' => calculateFunctionPoints($loc, $primaryLanguage),
        'slim' => calculateSLIM($loc),
        'putnam' => calculatePutnam($loc)
    ];
}