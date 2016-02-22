#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * LOVD scripts: Gene Loader
 *
 * (based on load_HGNC_data.php, created 2013-02-13, last modified 2015-10-08)
 * Created     : 2016-02-22
 * Modified    : 2016-02-22
 * Version     : 0.1
 * For LOVD    : 3.0-15
 *
 * Purpose     : To help the user automatically load a large number of genes into LOVD3, together with the desired
 *               transcripts, and optionally, the diseases.
 *               This script retrieves the list of genes from the HGNC and creates an LOVD3 import file format with the
 *               gene and transcript information. It checks on LOVD.nl whether or not to use LRG, NG or NC. It also
 *               queries Mutalyzer for the reference transcript's information, and puts these in the file, too.
 *
 * Copyright   : 2004-2016 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmer  : Ing. Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>
 *
 *
 * This file is part of LOVD.
 *
 * LOVD is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * LOVD is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with LOVD.  If not, see <http://www.gnu.org/licenses/>.
 *
 *************/

if (isset($_SERVER['HTTP_HOST'])) {
    die('Please run this script through the command line.' . "\n");
}

$_CONFIG = array(
    'version' => '0.1',
    // We ignore genes from the following locus groups:
    'bad_locus_groups' => array(
        'phenotype', // No transcripts.
        'withdrawn', // Do not exist anymore.
    ),
    // We ignore genes from the following locus types (most of these are in group "other"):
    'bad_locus_types' => array(
        'endogenous retrovirus',  // From group "other", none of them work (verified).
        'fragile site',           // From group "other", none of them work (verified).
        'immunoglobulin gene',    // From group "other", none of them work (verified).
        'region',                 // From group "other", none of them work (verified).
        'transposable element',   // From group "other", none of them work (verified).
        'unknown',                // From group "other", none of them work (verified).
        'virus integration site', // From group "other", none of them work (verified).
        'immunoglobulin pseudogene', // From group "pseudogene", none of them work (verified).
    ),
    'user' => array(
        // Variables we will be asking the user.
        'lovd_path' => '',
        'gene_list' => 'all',
        'transcript_list' => 'best',
    ),
    'hgnc_columns' => array(
        'gd_hgnc_id' => 'HGNC ID',
        'gd_app_sym' => 'Approved Symbol',
        'gd_app_name' => 'Approved Name',
        'gd_locus_type' => 'Locus Type',
        'gd_locus_group' => 'Locus Group',
        'gd_pub_chrom_map' => 'Chromosome',
        'gd_pub_eg_id' => 'Entrez Gene ID', // Curated by the HGNC.
        'gd_pub_refseq_ids' => 'RefSeq IDs', // Curated by the HGNC.
        'md_mim_id' => 'OMIM ID(supplied by OMIM)',
        'md_refseq_id' => 'RefSeq(supplied by NCBI)', // Downloaded from external sources.
    ),
    'lovd_gene_columns' => array(
        'id',
        'name',
        'chromosome',
        'chrom_band',
        'refseq_genomic',
        'refseq_UD',
        'id_hgnc',
        'id_entrez',
        'id_omim',
    ),
    'lovd_transcript_columns' => array(
        'id',
        'geneid',
        'name',
        'id_mutalyzer',
        'id_ncbi',
        'id_protein_ncbi',
        'position_c_mrna_start',
        'position_c_mrna_end',
        'position_c_cds_end',
        'position_g_mrna_start',
        'position_g_mrna_end',
    ),
);





function lovd_verifySettings ($sKeyName, $sMessage, $sVerifyType, $options)
{
    // Based on a function provided by Ileos.nl in the interest of Open Source.
    // Check if settings match certain input.
    global $_CONFIG;

    switch($sVerifyType) {
        case 'array':
            $aOptions = $options;
            if (!is_array($aOptions)) {
                return false;
            }
            break;

        case 'int':
            // Integer, options define a range in the format '1,3' (1 to 3) or '1,' (1 or higher).
            $aRange = explode(',', $options);
            if (!is_array($aRange) ||
                ($aRange[0] === '' && $aRange[1] === '') ||
                ($aRange[0] !== '' && !ctype_digit($aRange[0])) ||
                ($aRange[1] !== '' && !ctype_digit($aRange[1]))) {
                return false;
            }
            break;
    }

    while (true) {
        print('  ' . $sMessage .
            (empty($_CONFIG['user'][$sKeyName])? '' : ' [' . $_CONFIG['user'][$sKeyName] . ']') . ' : ');
        $sInput = trim(fgets(STDIN));
        if (!strlen($sInput) && !empty($_CONFIG['user'][$sKeyName])) {
            $sInput = $_CONFIG['user'][$sKeyName];
        }

        switch ($sVerifyType) {
            case 'array':
                $sInput = strtolower($sInput);
                if (in_array($sInput, $aOptions)) {
                    $_CONFIG['user'][$sKeyName] = $sInput;
                    return true;
                }
                break;

            case 'int':
                $sInput = (int) $sInput;
                // Check if input is lower than minimum required value (if configured).
                if ($aRange[0] !== '' && $sInput < $aRange[0]) {
                    break;
                }
                // Check if input is higher than maximum required value (if configured).
                if ($aRange[1] !== '' && $sInput > $aRange[1]) {
                    break;
                }
                $_SETT[$sKeyName] = $sInput;
                return true;

            case 'file':
            case 'lovd_path':
            case 'path':
                // Always accept the default or the given options.
                if ($sInput == $_CONFIG['user'][$sKeyName] ||
                    $sInput === $options ||
                    (is_array($options) && in_array($sInput, $options))) {
                    $_CONFIG['user'][$sKeyName] = $sInput; // In case an option was chosen that was not the default.
                    return true;
                }
                if (in_array($sVerifyType, array('lovd_path', 'path')) && !is_dir($sInput)) {
                    print('    Given path is not a directory.' . "\n");
                    break;
                } elseif (!is_readable($sInput)) {
                    print('    Cannot read given path.' . "\n");
                    break;
                }

                if ($sVerifyType == 'lovd_path') {
                    if (!file_exists($sInput . '/config.ini.php')) {
                        if (file_exists($sInput . '/src/config.ini.php')) {
                            $sInput .= '/src';
                        } else {
                            print('    Cannot locate config.ini.php in given path.' . "\n" .
                                  '    Please check that the given path is a correct path to an LOVD installation.' . "\n");
                            break;
                        }
                    }
                    if (!is_readable($sInput . '/config.ini.php')) {
                        print('    Cannot read configuration file in given LOVD directory.' . "\n");
                        break;
                    }
                    // We'll set everything up later, because we don't want to
                    // keep the $_DB open for as long as the user is answering questions.
                }
                $_CONFIG['user'][$sKeyName] = $sInput;
                return true;

            default:
                return false;
        }
    }

    return false; // We'd actually never get here.
}





// Obviously, we could be running for quite some time.
set_time_limit(0);





print('Gene Loader v' . $_CONFIG['version'] . '.' . "\n");

// Verify settings with user.
lovd_verifySettings('lovd_path', 'Path of LOVD installation to load data into', 'lovd_path', '');
lovd_verifySettings('gene_list', 'File containing the gene symbols that you want created,
    or just press enter to create all genes', 'file', '');
lovd_verifySettings('transcript_list', 'File containing the transcripts that you want created,
    type \'all\' to have all transcripts created,
    or just press enter to let LOVD pick the best transcript per gene', 'file', array('all', 'best'));





// Check gene and transcript files and file formats.
$aGenesToCreate = $aTranscriptsToCreate = array();
// STUB.

// Download HGNC data first. In case the user has given a gene list,
// we might not be able to send the full query to the HGNC,
// so better just download the whole thing and loop through it.

// Find LOVD installation, run it's inc-init.php to get DB connection, initiate $_SETT, etc.
?>