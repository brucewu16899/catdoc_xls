<?php
namespace CatDocXls;

class Parser
{
    private $sheet_delimiter_default = '~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~';

    public function xls($path, $sheet_delimiter = null)
    {
        $this->checkFile($path);

        $sheet_delimiter = $this->processPageDelimiter($sheet_delimiter);

        $cmd = "xls2csv " .
            "-d utf-8 " .
            "-c ';' " .
            "-b \"" . $sheet_delimiter . "\n\" " .
            $path . " 2>&1";
        $output = array();
        $exit_code = null;
        exec($cmd, $output, $exit_code);

        if ($exit_code !== 0) {
            throw new Exception('xls2csv failed: ' . $cmd . ', exit code ' . $exit_code . ', output: ' . join("\n", $output));
        }

        if ($output === '') {
            throw new Exception('xls2csv output empty');
        }

        $sheets = $this->divideSheets($output, $sheet_delimiter);
        $sheets = array_map(array($this, 'csvLinesToArray'), $sheets);

        return $sheets;
    }

    public function xls2($path, $sheet)
    {
        $this->checkFile($path);

        $temp_file = tempnam(sys_get_temp_dir(), 'xls2csv_py');

        $cmd = $this->getComposerExecutable('JEANNENOTStephane/xls2csv/xls2csv.0.4.py') . " " .
            "--input {$path} " .
            "--output {$temp_file} " .
            "--sheet {$sheet} " .
            "--enclose-text " .
            " 2>&1";

        $output = array();
        $exit_code = null;

        exec($cmd, $output, $exit_code);

        if ($exit_code !== 0) {
            unlink($temp_file);
            throw new Exception('xls2csv.0.4.py failed: ' . $cmd . ', exit code ' . $exit_code . ', output: ' . join("\n", $output));
        }

        if ($output === '') {
            unlink($temp_file);
            throw new Exception('xls2csv.0.4.py output empty');
        }

        $result = array();

        $handle = fopen($temp_file, 'r');
        while (($data = fgetcsv($handle, null, ';')) !== false) {
//            print_r($data);
            if ($data === array('')) continue; //ignoring empty lines, same all all other
            $result[] = $data;
        }
        fclose($handle);

//        var_dump(file_get_contents($temp_file));
//        print_r($result);

        unlink($temp_file);

        return $result;
    }

    public function xlsx($path, $sheet_delimiter = null)
    {
        $this->checkFile($path);

        $sheet_delimiter = $this->processPageDelimiter($sheet_delimiter);

        $cmd = $this->getComposerExecutable('dilshod/xlsx2csv/xlsx2csv.py') . " " .
            "--ignoreempty " .
            "--dateformat '%Y-%m-%d %H:%M:%S' " .
            "--delimiter ';' " .
            "--sheet 0 " .
            "--sheetdelimiter \"" . $sheet_delimiter . "\n\" " .
            $path . " 2>&1";
        $output = array();
        $exit_code = null;

        exec($cmd, $output, $exit_code);

        if ($exit_code !== 0) {
            throw new Exception('xlsx2csv failed: ' . $cmd . ', exit code ' . $exit_code . ', output: ' . join("\n", $output));
        }

        if ($output === '') {
            throw new Exception('xlsx2csv output empty');
        }

        array_shift($output); //remove first sheet delimiter

        $sheets = $this->divideSheets($output, $sheet_delimiter);

        //process lines
        foreach ($sheets as &$sheet) {
            if (count($sheet) === 1) {//ignoring empty sheet, same as xls2csv
                array_shift($sheets);
                continue;
            }
            array_shift($sheet); //remove sheet title, same as xls2csv
            $sheet = $this->csvLinesToArray($sheet);
        }

        return $sheets;
    }

    private function divideSheets(array $lines, $delimiter)
    {
        $sheets = array();
        do {
            $delimiter_pos = array_search($delimiter, $lines);
            if ($delimiter_pos === false) {
                if (!empty($lines)) {
                    $sheets[] = $lines;
                }
                break;
            }
            $sheets[] = array_splice($lines, 0, $delimiter_pos);
            array_splice($lines, 0, 1);
        } while (true);

        return $sheets;
    }

    private function processPageDelimiter($delimiter)
    {
        if (!$delimiter) {
            $delimiter = $this->sheet_delimiter_default;
        } elseif (strpos($delimiter, ' ') !== false) {
            throw new Exception('spaces in delimiter are not allowed');
        }

        return $delimiter;
    }

    private function getComposerExecutable($relative_path)
    {
        $try_files = array(
            __DIR__ . '/../../../../' . $relative_path,//from 3th-party projects as dependency
            __DIR__ . '/../../vendor/' . $relative_path,//from package repo
        );
        foreach ($try_files as $try_file) {
            if (is_file($try_file) && is_executable($try_file)) {
                $executable = $try_file;
                break;
            }
        }
        if (!isset($executable)) {
            throw new Exception("executable not found {$relative_path}");
        }
        return realpath($executable);
    }

    private function csvLinesToArray(array $csv_lines)
    {
        $result = array();

        //for correct handling multiline string values with fgetcsv we should pass csv through temporary file
        $handle = tmpfile();
        fwrite($handle, join(PHP_EOL, $csv_lines));
        fseek($handle, 0);

        while (($data = fgetcsv($handle, null, ';')) !== false) {
            $result[] = $data;
        }

        fclose($handle);

        return $result;
    }

    private function checkFile($path)
    {
        if (!is_file($path)) {
            throw new Exception('file not found - ' . $path);
        }

        if (!is_readable($path)) {
            throw new Exception('file unreadable - ' . $path);
        }
    }
}
