<?php
// Converts one Common Cartridge export from the command line, with the web form's settings, and unzips the result:
//   php cli.php <course.imscc> <output folder> [options]
// Options (each changes one of the web form's defaults):
//   helios              for Grav Helios Course Hub (default: Grav Open Course Space)
//   standard-markdown   Standard Markdown, without Grav shortcodes
//   with-files          keep attached files (PDFs, videos …)
//   no-image-downloads  link images at their own addresses instead of downloading them
//   keep-numbers        keep numbered titles as they are
//   no-essentials, no-resources, no-syllabus   leave out that page
//   slug=my-course      the course's folder name
// The output folder then has pages/ (and conversion-notes.txt), as in the downloaded zip.
// Only from the command line: on the web server it does nothing.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/vendor/autoload.php';

use CH\CartridgeParser;
use CH\CourseHubBuilder;

// Removes a folder and everything in it (CHILD_FIRST lists a folder's contents before the folder itself)
function removeFolder(string $dir): void
{
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

if (count($argv) < 3) {
    fwrite(STDERR, "Usage: php cli.php <course.imscc> <output folder> [helios] [standard-markdown] [with-files] [no-image-downloads] [keep-numbers] [no-essentials] [no-resources] [no-syllabus] [slug=my-course]\n");
    exit(1);
}
$course = $argv[1];
$out = rtrim($argv[2], '/');
if (!is_file($course)) {
    fwrite(STDERR, "No such file: $course\n");
    exit(1);
}
$options = array_slice($argv, 3);

// the web form's defaults, then any changes asked for
$destination = in_array('helios', $options, true) ? 'helios' : 'open-course-space';
$portableMarkdown = in_array('standard-markdown', $options, true);
$skipFiles = !in_array('with-files', $options, true);
$skipImageDownload = in_array('no-image-downloads', $options, true);
$stripTitleNumbering = !in_array('keep-numbers', $options, true);
$includeEssentials = !in_array('no-essentials', $options, true);
$includeResources = !in_array('no-resources', $options, true);
$includeSyllabus = !in_array('no-syllabus', $options, true);
$slug = '';
foreach ($options as $option) {
    if (str_starts_with($option, 'slug=')) {
        $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower(substr($option, strlen('slug='))));
    }
}

// the export is a zip: unpack it into a temporary folder (all of it – the web form leaves out attached files it skips,
// but the converter skips them either way)
$zip = new ZipArchive();
if ($zip->open($course) !== true) {
    fwrite(STDERR, "That file isn't a Common Cartridge (zip) file: $course\n");
    exit(1);
}
$work = sys_get_temp_dir() . '/cc-cli-' . getmypid();
mkdir($work, 0700, true);
$zip->extractTo($work);
$zip->close();

$parser = new CartridgeParser($work);
if (!$parser->isValid()) {
    removeFolder($work);
    fwrite(STDERR, "No supported content was found in $course\n");
    exit(1);
}
if ($slug !== '') {
    $parser->courseSlug = $slug;
}
$builder = new CourseHubBuilder($parser, $skipFiles, $skipImageDownload, $stripTitleNumbering, $includeEssentials, $includeResources, $includeSyllabus, $portableMarkdown, $destination);
$zipPath = $builder->build();

// the result, unzipped into the output folder
$result = new ZipArchive();
$result->open($zipPath);
$result->extractTo($out);
$result->close();
unlink($zipPath);
removeFolder($work);

echo "Converted \"{$parser->courseTitle}\" to $out/pages/\n";
