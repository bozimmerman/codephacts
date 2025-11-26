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
function analyzeCStyleComment($line, &$state) 
 {
     $len = strlen($line);
     $hasCode = false;
     $hasComment = false;
     $i = 0;
     while ($i < $len) 
     {
         // If we're in a block comment, look for end
         if ($state['inBlockComment']) 
         {
             $hasComment = true;
             if ($i < $len - 1 && $line[$i] === '*' && $line[$i + 1] === '/') 
             {
                 $state['inBlockComment'] = false;
                 $i ++;
             }
             $i++;
             continue;
         }
         if ($i < $len - 1 && $line[$i] === '/' && $line[$i + 1] === '*') 
         {
             $hasComment = true;
             $state['inBlockComment'] = true;
             $i += 2;
             continue;
         }
         if ($i < $len - 1 && $line[$i] === '/' && $line[$i + 1] === '/') 
         {
             $hasComment = true;
             break; // Rest of line is comment
         }
         if (!ctype_space($line[$i])) 
             $hasCode = true;
         $i++;
     }
     
     return [
         'has_comment' => $hasComment,
         'is_comment' => $hasComment && !$hasCode,
         'has_code' => $hasCode
     ];
 }