<?php

if (!defined('PmWiki')) exit();

#--- Public API --------------------------------------------------

#Add this before anything else; this initializes the BibTexQuery engine
#
Markup('bibinit','<bibtexquery','/bibinit:(?:\[([^\]]*)\])?(?:\[([^\]]*)\])?/','LoadPrologue');

#Shows an editor for the BibTex file given in arg1
#
Markup('editbib', 'directives','/\\(:editbib\\s+([^:]+):\\)/',"EditBibForm");

#Select and show Bibtex entries from the file arg1, which match condition in arg2, grouped by arg3, sorted by arg4, capped to max given by arg5
#
Markup("bibtexquery","fulltext","/\\bbibtexquery:\\[(.*?)\\]\\[(.*?)\\]\\[(.*?)\\]\\[(.*?)\\]\\[(.*?)\\]/","BibQuery_callback");

#Displays a grid of arg1 random thumbnails picked from those present in the Bibtex database
#
Markup('bibthumbsgallery', 'directives', '/\\(:bibthumbsgallery\\s+([^:]+):\\)/', 'Bibthumbs_make_gallery');

#Displays a D3 chart with some stats about the papers selected by BibQuery
#
Markup('bibchart', 'directives', '/\(:bibchart\s*(.*?)\s*:\)/', "BibChart_callback");




   
#--- All below is not API but implementation so not interesting for plugin users ----------------------------------------

#Code below tries to remember, in the $Bibtex_goback global var, where this page was invoked from. Used to enable 'subpages', i.e.
#this page invoked with various actions or parameters, go back to where they were invoked from.
#NB: all code below doesn't change $_SESSION['return_to'] if in POST mode, since then we're only reacting to stuff

pm_session_start();
if (!isset($_SESSION['return_to']))                   // First time we enter this flow, remember the current page
{
    $Bibtex_goback = null; 
    if ($_SERVER['REQUEST_METHOD'] === 'GET') $_SESSION['return_to'] = $_SERVER['REQUEST_URI'];
} 
else                                                  // We're now in a new page; remember where we came from; store where we are for future use 
{
    $Bibtex_goback = $_SESSION['return_to'];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') $_SESSION['return_to'] = $_SERVER['REQUEST_URI']; // erase after first use
}



include 'PDFToThumbnails.phpclass';                             #Include the API of the PDF thumbnail extractor
 

//Local vars: These keep state which is local to stuff in this plugin:

$BibEntries = array();                                          //State var: contains all parsed Bibtex entries

$BibMemberAuthors = array();                                  //State var: contains all special authors to use in memberAuthors() 

$lastChar = '';                                                 //State var: helps with generating markup content

$BibtexKeepFlag = false;                                        //State var: controls how xKeep() works, see below

$BibThumbC = ".bib-thumb-c";

$BibtexBibDir = $UploadDir . "/Bibtex";
$BibtexBibUrl = $UploadUrlFmt . "/Bibtex";
$BibtexCustomFilename = "bib_custom";
$BibtexThumbsCache = "thumbs.cache";
$BibtexHelpDoc = "documentation.txt";
$BibtexBibUrlShort = preg_replace('#^https?://[^/]+#', '', $BibtexBibUrl); //create short, root-relative, URL from the fully qualified one given in config


SDV($HandleActions['editbib'],'HandleEditBib');                 //Callback for "?action=editbib"
SDV($HandleAuth['editbib'],'edit');                             //Authorization level for HandleEditBib; not sure if needed
SDV($HandleActions['bibentry'],'HandleBibEntry');               //Action for clicking on a "BibTeX" thumbnail in the wiki page 
SDV($HandleActions['managebib'],'HandleManageBib');             //Action for managing all the stored stuff for the BibTex engine
                                                                //Load next whatever Javascripts are needed for this engine to work

SDV($HTMLHeaderFmt['mathjax-config'],                           //Add MathJax config before loading MathJax JS - needed so MathJax works 
"<script> window.MathJax = {tex: { inlineMath: [[\"$\",\"$\"],[\"\\\\(\",\"\\\\)\"]] } }; </script>");
SDV($HTMLHeaderFmt['mathjax'], "<script src='https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js'></script>");
SDV($HTMLHeaderFmt['d3'], "<script src='https://d3js.org/d3.v7.min.js'></script>");
SDV($HTMLHeaderFmt['bibinit'], "<script src='$PubDirUrl/bibtexref/bibtexref.js'></script>");
SDV($HTMLHeaderFmt['editbib'], "<script type='text/javascript' src='" . htmlspecialchars($PubDirUrl) . "/pako/pako.min.js'></script>");

//First list handlers for POST actions. Since this script gets exec'd both when a HTML page is constructed and also when clients sent answers (POST)
//to the server, in the latter case, it's faster to just handle those actions and exit processing

                                                                                      //We need to have the PMWiki Keep() function either doing what it does
function xKeep($arg)                                                                  //or simply returning its argument, depending on the call context. For this,
{ global $BibtexKeepFlag; return ($BibtexKeepFlag)? Keep($arg) : $arg; }              //we define a variant which acts depending on the value of a global flag
                                                                                      //WARNING: Very ugly but I don't know how to do this more elegantly

function FlushCache()                                                                 //To speed up things, we keep a cache of the stuff computed by 'bibtexquery';
{                                                                                     //When the query params change or the buttons in the UI say some other sorting etc
    global $BibtexBibDir, $BibtexThumbsCache;                                         //criteria change, we call this to invalidate (delete) that cache
    $cachefile = $BibtexBibDir . "/" . "cache_*";
    $files = glob($cachefile); 
    
    foreach ($files as $file) 
        if (is_file($file)) unlink($file);

    $cacheFile = $BibtexBibDir . "/" . $BibtexThumbsCache;
    if (is_file($cacheFile)) unlink($cacheFile);
}

function DeleteThumbDir($dir)
{
    if (!is_dir($dir)) return;

    $files = array_diff(scandir($dir), array('.', '..'));

    foreach ($files as $file) 
    {
        $fullPath = "$dir/$file";
        if (!is_dir($fullPath)) unlink($fullPath);
    }
}


function HandleSortAction($act)                                                       //Callback for sort buttons 
{
    $state = isset($_COOKIE['bibtex_sort']) && $_COOKIE['bibtex_sort']==$act ? '' : $act;
    setcookie("bibtex_sort", $state, time() + 3600, "/");
}

function HandleGroupAction($act)                                                       //Callback for sort buttons 
{
    $state = isset($_COOKIE['bibtex_group']) && $_COOKIE['bibtex_group']==$act ? '' : $act;
    setcookie("bibtex_group", $state, time() + 3600, "/");
}

function HandleSearchAction($query)                                                     //Callback for search-for-author textbox 
{
    $q = htmlspecialchars($query);
    setcookie("bibtex_author", $q, time() + 3600, "/");
}


function HandleLODAction($act) 								//Callback for changing the level-of-detail of page display
{
   if (isset($_COOKIE['level_of_detail']))
   {
     if ($_COOKIE['level_of_detail']=='Full') 
       $state = 'Medium';
     elseif ($_COOKIE['level_of_detail']=='Medium')
       $state = 'Minimal';
     elseif ($_COOKIE['level_of_detail']=='Minimal')
       $state = 'Full';
   }
   else 
     $state = 'Medium';

   setcookie("level_of_detail", $state, time() + 3600, "/");                            //NB: Don't flush cache here - we cache all LOD versions of a page  
}

function HandleChartAction($act)                                                          //Callback for changing the showing of statistical charts
{   
   if (isset($_COOKIE['show_charts']))
   {                                                  
     if ($_COOKIE['show_charts']=='Yes')
       $state = 'None';
     elseif ($_COOKIE['show_charts']=='None')
       $state = 'Yes';
   }
   else 
     $state = 'Yes';
    
   setcookie("show_charts", $state, time() + 3600, "/");                            //NB: Don't flush cache here - we cache all LOD versions of a page  
}



function HandleNumberEntries($act)
{
    $state = isset($_COOKIE['bibtex_number']) ? '' : $act;
    setcookie("bibtex_number", $state, time() + 3600, "/");
}


if ($_SERVER['REQUEST_METHOD'] === 'POST')                                   //This block of code handles all POST requests for this webpage
{
  if (isset($_POST['delete_selected_thumbs']))
  {
     $selectedDir = $_POST['dir'];
     if (!empty($_POST['selected_images'])) 
     {
         foreach ($_POST['selected_images'] as $filename) 
         {
            $filename = $BibtexBibDir . "/" . $selectedDir . "/" . $filename;
            if (file_exists($filename))
              unlink($filename);   
         }
     }
     header("Location: " . "?action=managebib?selectedDir=" . $selectedDir);
     exit;
  }
  else if (isset($_POST['delete_pdf']))
  {
     $key = $_POST['key'];
     $selectedDir = $_POST['dir'];

     $pdf_file = $BibtexBibDir . "/" . $key . ".pdf";

     if (file_exists($pdf_file))
        unlink($pdf_file);

     header("Location: " . "?action=managebib?selectedDir=" . $selectedDir);
     exit;
  }
  else if (isset($_POST['upload_pdf']) && isset($_FILES['uploaded_file']))
  {
     $key = $_POST['key'];
     $selectedDir = $_POST['dir'];

     $fileTmpPath = $_FILES['uploaded_file']['tmp_name'];
     $targetFilePath = $BibtexBibDir . "/" . $key . ".pdf";

     $ok = move_uploaded_file($fileTmpPath, $targetFilePath);

     header("Location: " . "?action=managebib?selectedDir=" . $selectedDir);
     exit;
  }
  else if (isset($_POST['upload_thumbs']) && isset($_FILES['uploaded_file']))
  {
     $selectedDir = $_POST['dir'];

     $fileTmpPath = $_FILES['uploaded_file']['tmp_name'];
     $fileName = basename($_FILES['uploaded_file']['name']);
     $targetFilePath = $BibtexBibDir . "/" . $selectedDir . "/";

     if ($_FILES['uploaded_file']['size'] <=  1024 * 1024)  
     { 
       $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
       if ($fileType == "jpg")
       {
          $index = 0;
          do {
            $newFileName = $targetFilePath . $BibtexCustomFilename . "." . $index . ".jpg";
            $index++;
        } while (file_exists($newFileName));
          
        $ok = move_uploaded_file($fileTmpPath, $newFileName);
       }
     }

     header("Location: " . "?action=managebib?selectedDir=" . $selectedDir);
     exit;
  }
  else if (isset($_POST['rebuild_all_thumbs'])) 
  {
     $key = $_POST['key'];
     $thumbs_dir = $key . "_thumbs";
     $bibfile = $_POST['bibfile']; 
     
     $bibentry = GetEntry($bibfile,$key);
     $pdf = $bibentry->getPDF();
     
     if ($pdf)										//If we got a PDF, re-create thumbs for it
     {
         $qual_thumbs_dir = $BibtexBibDir . "/" . $thumbs_dir;
        
         $pdf_thumbs = glob($qual_thumbs_dir . "/" . $key . '.*.jpg'); 
         foreach($pdf_thumbs as $file)
            unlink($file);
         //!!possibly add code to delete all _raw_image temp files

         $processed_log = $qual_thumbs_dir . "/processed.log";
         unlink($processed_log);

         PDFToThumbnails::computeThumbnails($pdf, $key, $BibtexBibDir, 10); 
         touch($processed_log);
     }


     header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
     header("Cache-Control: post-check=0, pre-check=0", false);
     header("Pragma: no-cache");
     header("Location: ?action=managebib?selectedDir=$thumbs_dir");
     exit;
  }
  else if(isset($_POST['delete_all_thumbs']))
  {
     if (isset($_POST['dir']))
     {
       $dir = $_POST['dir'];
       $fullPath = realpath("$BibtexBibDir/$dir");
       
       DeleteThumbDir($fullPath);       
     }
     header("Location: ?action=managebib");
     exit;
  }
  else if (isset($_POST['selectedDir']))  
  {
     $selectedDir = $_POST['selectedDir'];
     
     header("Location: " . "?action=managebib?selectedDir=" . $selectedDir);
     exit;
  }
  else if (isset($_POST['delete_caches']))
  {
      FlushCache();    
      header("Location: ?action=managebib");                                              //Reload page; cookie tells author name
      exit;
  }
  else if (isset($_POST['level_of_detail']))
  {
      HandleLODAction($_POST['level_of_detail']);
      header("Location: ?action=browse");                                                 //Reload page; cookie tells author name
      exit;
  }
  else if (isset($_POST['show_charts']))
  {
      HandleChartAction($_POST['show_charts']);
      header("Location: ?action=browse");                                                 //Reload page; cookie tells author name
      exit;
  }
  else if (isset($_POST['sort_action']))
  {
      HandleSortAction($_POST['sort_action']);
      header("Location: ?action=browse");                                                 //Reload page; cookie tells author name
      exit;  
  }
  else if (isset($_POST['group_action']))
  {
      HandleGroupAction($_POST['group_action']);
      header("Location: ?action=browse");                                                 //Reload page; cookie tells author name
      exit;  
  } 
  else if (isset($_POST['search_author']))
  {
      HandleSearchAction($_POST['search_author']);
      header("Location: ?action=browse");                                                 //Reload page; cookie tells author name
      exit;  
  }
  else if (isset($_POST['number_entries']))
  {
      HandleNumberEntries($_POST['number_entries']);
      header("Location: ?action=browse");                                                 //Reload page; cookie tells author name
      exit;  
  }
  else if (isset($_POST['bibtext_compressed'])) 
  {
    $content = gzdecode(base64_decode($_POST['bibtext_compressed']));
    $bibFile = "$BibtexBibDir/" . trim($_POST['filename']);
    $bibBackup = $bibFile . ".backup"; 							//Make a backup of this edit, just in case; not sure
    copy($bibFile, $bibBackup);								//how to generalize this and let users undo the edits...
    file_put_contents($bibFile, $content);
    FlushCache();

    $goback = $_POST['goback'];                                                         //See where we got called from, return there

    header("Location: $goback");                                                        //Reload page; cookie tells author name
    exit;
  }
  else if (isset($_POST['search_keywords']))
  {
    $kwd = $_POST['search_keywords']; 
    setcookie("bibtex_keywords", $kwd, time() + 3600, "/");                                 
    header("Location: ?action=browse");                                                 //Reload page; cookie tells author name
    exit;
  }
}



function LoadPrologue($v)                                                            //Adds (at top of page) all code which should appear before any
{								                     //actual Bib entries are added. So far, this is code for select/sort/etc UI 
  global $PubDirUrl, $BibMemberAuthorsi, $BibThumbC;                                 //and JS code doing the thumbnail animation. Also sets some global vars

  $ret = "";							                  

  $args = [trim($v[1] ?? ''), trim($v[2] ?? '')];                                    //Parse optional arguments

  $show_gui = false;
  $BibMemberAuthors = [];

  foreach ($args as $arg) 
  {
    if ($arg === '') continue;

    if (stripos($arg, 'gui') !== false) { $show_gui = true; } 
    else 
        foreach (explode(',', $arg) as $p) {
            $p = trim($p, " \t\n\r\0\x0B\"'");
            if ($p !== '') $BibMemberAuthors[] = $p;
        }
  }

  if ($show_gui)                                                                    //Add HTML for the UI that sorts/groups/etc the references
  {
    $html = "<div style='width: 100%; display: flex; justify-content: flex-start; align-items: center'>";
             
    $currentSort = isset($_COOKIE['bibtex_sort']) ? $_COOKIE['bibtex_sort'] : '';   //Add HTML for sorting entries; show currently-set sort mode if any

    $html .= "<span style='margin: 0; padding-right: 5px'><strong>Sort</strong></span>
             <form method='post'>
             <select name='sort_action' onchange='this.form.submit()'>";

    $sortButtons = [ 'default' => '', 'author' => 'sort_author', 'type' => 'sort_type', 'year' => 'sort_year' ];
    foreach ($sortButtons as $buttonText => $action) 
    {
      $selected = ($currentSort === $action) ? "selected" : "";
      $html .= "<option value='$action' $selected>$buttonText</option>";
    }

    $html .= "</select></form>";

    $ret .= Keep($html) ."\n";

    $currentGroup = isset($_COOKIE['bibtex_group']) ? $_COOKIE['bibtex_group'] : '';   //Add HTML for grouping entries; show currently-set group mode if any

    $html = "<span style='margin: 0; padding-left: 5px; padding-right: 5px'><strong>Group</strong></span>
             <form method='post'>
             <select name='group_action' onchange='this.form.submit()'>";
    
    $groupButtons = [ 'default' => '', 'author' => 'group_author', 'type' => 'group_type', 'year' => 'group_year' ];

    if (!empty($BibMemberAuthors)) $groupButtons['members'] = 'group_members';         //Add entry 'members' only of we have specified $BibMemberAuthors (else useless/confusing)      

    foreach ($groupButtons as $buttonText => $action)
    {
      $selected = ($currentGroup === $action) ? "selected" : "";
      $html .= "<option value='$action' $selected>$buttonText</option>";
    }

    $html .= "</select></form>";

    $ret .= Keep($html) ."\n";


    $currentAuthor = isset($_COOKIE['bibtex_author']) ? $_COOKIE['bibtex_author'] : ''; //Add HTML for selecting one author; show currently-set author if any
 
    $ret .= Keep("<span style='margin: 0; padding-left: 5px; padding-right: 5px'><strong>Author</strong></span>
                  <form method='post' action='?action=search_author'>
                    <input type='text' name='search_author' placeholder = 'Select' value='$currentAuthor' style='width: 60px;'/>
                  </form>") . "\n";

    $currentKeyword = isset($_COOKIE['bibtex_keywords']) ? $_COOKIE['bibtex_keywords'] : ''; //Add HTML to select by keywords; show currently-set keyword if any

    $ret .= Keep("<span style='margin: 0; padding-left: 5px; padding-right: 5px'><strong>Keyword</strong></span>
                  <form method='post' action='?action=search_keywords'>
                    <input type='text' name='search_keywords' placeholder = 'Select' value='$currentKeyword' style='width: 60px;'/>
                  </form>") . "\n"; 

    $currentLOD = isset($_COOKIE['level_of_detail']) ? $_COOKIE['level_of_detail'] : 'Full'; //Add HTML to change level of detail; show currently-set LOD if any

    $ret .= Keep("<span style='margin: 0; padding-left: 5px; padding-right: 5px'><strong>Detail</strong></span>
                  <form method='post' action='?action=level_of_detail'>
                    <input type='hidden' name='level_of_detail' value='level_of_detail'>
                  <button type='submit'> $currentLOD </button> </form>") . "\n"; 

    $currentCharts = isset($_COOKIE['show_charts']) ? $_COOKIE['show_charts'] : 'None'; //!!!
    $ret .= Keep("<span style='margin: 0; padding-left: 5px; padding-right: 5px'><strong>Stats</strong></span>
                  <form method='post' action='?action=show_charts'>
                    <input type='hidden' name='show_charts' value='show_charts'>
                  <button type='submit'> $currentCharts </button> </form>") . "\n";

    $highlightStyle = isset($_COOKIE['bibtex_number']) ? "style='font-weight: bold;'" : "";  //Add HTML to set/reset reference numbering; show currently-set value if any
               
    $ret .= Keep("<p style='margin: 0; padding-left: 5px'></p>
                  <form method='post' action='?action=number_entries'>
                   <input type='hidden' name='number_entries' value='number_entries'>
                  <button type='submit' $highlightStyle> &#35; </button> </form>") . "\n";
               
    $ret .= Keep("</div> ") ."\n";				                //Finish adding buttons
  }
 
  $ret .= <<<EOT
      <script>
        document.addEventListener('DOMContentLoaded', function()        // When HTML doc fully loaded, add callback for mouse-enter
        {                                                               // to start the animations
            document.querySelectorAll('.bib-thumb-c').forEach(function(container)
            {
              var thumbnailId = container.id.split('-')[1];
              container.addEventListener('pointerenter', function() { cycleImages(thumbnailId); });
            });
        });
      </script>
  EOT;
     
  return $ret;
}
    

function Bibthumbs_make_gallery($v)
{
  global $BibtexBibDir, $BibtexBibUrl, $BibtexThumbsCache; 
 
  $N = trim($v[1]);                                                      // Get how many thumbnails to show in the gallery
 
  $cacheFile = $BibtexBibDir . "/" . $BibtexThumbsCache;                 // Try to get the cached filenames for all thumbnails

  if (file_exists($cacheFile))
  {                                                                      // Cache file is valid, read from it
    $files = unserialize(file_get_contents($cacheFile));
  }
  else                                                                   // No cache file, must search all thumb-dirs
  {                                                                      // and get filenames. This is quite slow, so
    $files = find_thumb_images($BibtexBibDir);                           // cache the search results afterwards
    if (!$files) return "<div>No thumbnails found.</div>";
    file_put_contents($cacheFile, serialize($files));
  }

  $urls = array_map(function($path) use ($BibtexBibDir, $BibtexBibUrl)   //Convert filenames to URLs
   { return $BibtexBibUrl . str_replace($BibtexBibDir, '', $path); }, $files);

  $json = json_encode($urls);

  ob_start();
  ?>
  <div id="randthumbs-gallery"></div>
  <script>
    startRandomGallery("randthumbs-gallery", <?= $json ?>, <?= $N ?>, 3000);
  </script>
  <?php
  return ob_get_clean();
}


function find_thumb_images($base)                              // Find a subset of thumb-images to use in the gallery animation. 
{                                                              // For this, we (1) get all custom images explicitly uploaded by users;
  $result = [];                                                // (2) If, for a thumbs-dir, we don't have such images, just get one 
                                                               // of the auto-generated images from the PDF (if any)
  // Get immediate subdirs matching *_thumbs
  foreach (glob($base . '/*_thumbs', GLOB_ONLYDIR) as $thumbDir) 
  {
    $matches = glob($thumbDir . '/' . $BibtexCustomFilename . '*.jpg');           // Get all the user-supplied thumb-images in this dir 

    if (!empty($matches))
      $result = array_merge($result, $matches);
    else                                                       // No user-supplied thumb-images? Get one auto-generated image
    {                                                          // (if any present)
      $fallbacks = glob($thumbDir . '/*.jpg');                 // For now, the heuristic is to get the largest such image
      if (!empty($fallbacks))                                  // (since likely also the most information-rich...)
       {
          usort($fallbacks, function($a, $b) { return filesize($b) - filesize($a); });
          $result[] = $fallbacks[0];
       }
    }
  }

  return $result;
}







function GenerateThumbdirButtons($bibfile) 
{
    global $BibtexBibDir;

    $dirs = glob("$BibtexBibDir/*_thumbs", GLOB_ONLYDIR);
    if (!$dirs) return "<p>No matching directories found.</p>";

    $html = "<div style='max-height: 400px; font-size: 50%; width: 100%; overflow-y: auto; border: 1px solid #ccc; padding: 1px; box-sizing: border-box; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);'>
                <table style='width: 100%; border-collapse: collapse;'>";

    $cols = 3;                                                 // Number of columns
    $count = 0;

    foreach ($dirs as $dir) 
    {
        $dirName = basename($dir);

        if ($count % $cols == 0) $html .= "<tr>";              // Start a new row

        $dirLabel = preg_replace('/_thumbs$/', '', $dirName);

        $bibentry = GetEntry($bibfile,$dirLabel);              // Does the current thumbs-dir match a Bibtex entry? If so, great, list it
       
        if ($bibentry)
        { 
            $html .= "<td style='padding: 1px; text-align: center;'>
                        <button type='submit' name='selectedDir' value='$dirName' style='width: 100%;  padding: 0px; margin: 1px; cursor: pointer;'> $dirLabel </button>
                      </td>";
        }
        else                                                   // If not, delete the thumbs-dir since very likely just something stale from the past..
        {   //!!For now, this is blocked since if we e.g. have a parsing error in Bibtex, this'll erase all cached thumb-dirs...
            // DeleteThumbDir($dir);                
            // rmdir($dir);                 
        }

        if ($count % $cols == $cols - 1) $html .= "</tr>"; // Close the row

        if ($bibentry) $count++;
    }

    if ($count % $cols != 0) $html .= "</tr>"; // Close any open row
    $html .= "</table></div>";

    return $html;
}


function HandleManageBib($pagename, $auth)
{
    if (!CondAuth($pagename, 'edit'))                           #Check if current user is allowed to edit at all
    {                                                           #If not, invoke the login mechanism of PMWiki; if login OK, continue displaying the managebib page
        RetrieveAuthPage($pagename, 'edit');
        Redirect($pagename . "?action=managebib");
        exit;
    }
  
    global $BibtexBibUrlShort, $BibtexThumbsCache, $BibtexHelpDoc, $BibtexBibDir, $BibtexBibUrl, $BibtexPdfLink, $PageStartFmt, $PageEndFmt;


    $page = RetrieveAuthPage($pagename, 'read', true);          //We next retrieve the wiki code of this page. 
    if (!$page) Abort("Cannot read page $pagename");            //We manually search it for the (:managebib xxx:) string
                                                                //and parse this string to extract the name 'xxx' of the Bibtex file
    $text = @$page['text'];                                     //This works since we've taken over, via this handler, the entire
                                                                //generation of the HTML page. 
    $bibfile = '';
    if (preg_match('/\\(:managebib\\s+(.*?)\\s*:\\)/', $text, $m)) 
    {
        $bibfile = $m[1];
    }
    else Abort("No markup (:managebib:) present in the current page. This is needed to specify which Bibtex file you want to manage");


    $files = glob("$BibtexBibDir/cache_*");                     //Find total size of HTML cache files
    $totalSize = 0;
    foreach ($files as $file) 
       $totalSize += filesize($file); 

    $thumbsCacheSize = 0;                                       //Find size of thumbs cache file
    $cacheFile = $BibtexBibDir . "/" . $BibtexThumbsCache;
    if (is_file($cacheFile)) $thumbsCacheSize = filesize($cacheFile);	

    $totalSize = round($totalSize / 1024, 2);
    $thumbsCacheSize = round($thumbsCacheSize / 1024, 2);

    $buttonsHtml = GenerateThumbdirButtons($bibfile);           //Build table with buttons for all papers which were processed
   
    $customContent = "<h1>Manage: $pagename</h1>
                      <br>
                      <p>Usage instructions? See bottom of this page.</p></br>";

    $customContent .= "<div style='margin-top: 10px; margin-bottom: 10px; border: 1px solid black; padding: 10px; border-radius: 5px;'>";

    $customContent .= "<p>Delete the various caches to refresh all from the BibTeX file. You <b>must</b> do this if you do edits below.</p><br>
                      <form method='post'>
                      <input type='submit' name='delete_caches' value='Delete caches'> Size $totalSize KB (HTML) $thumbsCacheSize KB (thumbs for gallery)
                      </form>
                      </div>
                      <br>
                      <p>Select a BibTeX record to manage its details</p>
                      <form method='post'>
                        $buttonsHtml
                      </form>";
    
    $selectedDir = isset($_GET['selectedDir']) ? $_GET['selectedDir'] : null;
								//Was this page called after some thumb-dir was selected?
    if ($selectedDir)						//Then display the details for that thumb-dir
    {
       $key = preg_replace('/_thumbs$/', '', $selectedDir); 

       
       $customContent .= "<div style='margin-top: 10px; margin-bottom: 10px; border: 1px solid black; padding: 10px; border-radius: 5px;'>";

       $customContent .= "<p>Thumbnail images for <b>$key</b> (<b>PDF</b>: generated from PDF; <b>Custom</b>: user uploads)</p>";

       $images = glob("$BibtexBibDir/$selectedDir/*.jpg");

       $customContent .= "<form method='post'>";
       $customContent .= "<div style='display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; text-align: center;'>";

       foreach ($images as $image) 
       {
          $imageSize = round(filesize($image)/1024, 2);       //Get the thumbnail file size to display it next

          $imageName = basename($image);

          $customImage = (substr($imageName, 0, strlen($BibtexCustomFilename)) === $BibtexCustomFilename);

          $customTag = ($customImage)? "Custom" : "PDF";

          $customContent .= "<div style='text-align: center; position: relative;'>
                               <div style='aspect-ratio: 1 / 1; display: flex; align-items: center; justify-content: center; overflow: hidden position=relative;' class='bibtex-zoom-wrapper'>
                                 <img src='" . $BibtexBibUrl . "/" . $selectedDir . "/". $imageName . 
                                     "' width='$imgWidth' style='max-width: 100%; max-height: 100%; object-fit: contain;' class='bibtex-thumb'>
                                 <img src='" . $BibtexBibUrl . "/" . $selectedDir . "/$imageName' class='bibtex-zoomed'>
                               </div>
                              $imageSize KB (<b>$customTag</b>) <input type='checkbox' name='selected_images[]' value='" . $imageName . "'> 
                             </div>";
       }
       $customContent .= "</div>";

       $bibentry = GetEntry($bibfile,$key);
       $pdf = $bibentry->getPDF();
       $pdf_file = $BibtexBibDir . "/" . $key . ".pdf";

       $customContent .= "<br><div style='width: 100%; display: flex; justify-content: flex-start; align-items: center; gap: 5px;'>";

       $customContent .= "<input type='submit' name='delete_selected_thumbs' value='Delete selected'>
                          <input type='hidden' name='dir' value='$selectedDir'>";

       $customContent .= "<input type='submit' name='delete_all_thumbs' value='Delete all'>
                          <input type='hidden' name='dir' value='$selectedDir'>";
       if ($pdf)
       {
         $customContent .= "<input type='submit' name='rebuild_all_thumbs' value='Rebuild thumbnails from PDF'>
                            <input type='hidden' name='key' value='$key'>
                            <input type='hidden' name='bibfile' value='$bibfile'>";
       }

       $customContent .= "</div>";
       $customContent .= "</form>";       

       $customContent .= "<form method='POST' enctype='multipart/form-data' style='display: inline-block; margin: 0;'>
                            <input type='file' name='uploaded_file' accept='.jpg' required>
                            <input type='hidden' name='dir' value='$selectedDir'>
                            <input type='submit' name='upload_thumbs' value='Upload thumbnail'>
                          </form><br>";

       $customContent .= "</div>";      

       $customContent .= "<div style='margin-top: 10px; margin-bottom: 10px; border: 1px solid black; padding: 10px; border-radius: 5px;'>";

       $customContent .= "<p>PDF details for <b>$key</b></p>";

       if ($pdf)                                                 // If we have a PDF (local or via links), allow user to check it
       {
          $customContent .= "<br><p>PDF file: " .  ((file_exists($pdf_file))? "Local upload" : "BibTex specified (no custom PDF upload)") . "</p>";

          $imageUrl = $BibtexBibUrlShort . "/". $BibtexPdfLink;       
          $customContent .= "<br><p>Check the PDF <a href='$pdf'><img src='$imageUrl' class='bibtex-small-thumb-cont'/></a></p>";
       }
       else
       {
          $customContent .= "<br><p>No PDF available (no local upload or specified in BibTeX)</p><br>";
       }

                                                                 // Add option to upload a local PDF file (always allowed if user wants this)
       $customContent .= "<br><form method='POST' enctype='multipart/form-data' style='display: inline-block; margin: 0;'>
                            <input type='file' name='uploaded_file' accept='.pdf' required>
                            <input type='hidden' name='key' value='$key'>
                            <input type='hidden' name='dir' value='$selectedDir'>
                            <input type='submit' name='upload_pdf' value='Upload custom PDF'>
                          </form><br>";
       
      if (file_exists($pdf_file))                               // If a local PDF was uploaded, allow user to delete it
      {
         $customContent .= "<br><form method='post'><input type='submit' name='delete_pdf' value='Delete uploaded PDF'>
                            <input type='hidden' name='key' value='$key'>
                            <input type='hidden' name='dir' value='$selectedDir'></form><br>"; 
      }

      $customContent .= "</div>";

      $customContent .= "<div style='margin-top: 10px; margin-bottom: 10px; border: 1px solid black; padding: 10px; border-radius: 5px;'>";
      $customContent .= "<p>BibTeX details for <b>$key</b></p><br>"; // Add option to edit the Bibtex record for the current entry
      $customContent .= "<button onclick=\"window.location.href='?action=editbib&key=$key';\">Edit BibTeX record</button>";
      $customContent .= "</div>";
    }

    $customContent .= "<br><button onclick=\"window.location.href='?action=browse';\">Back</button>";

    $helpfile = __DIR__ . '/' . $BibtexHelpDoc;                // Add, at the end, the help/documentation instructions for BibTexRef
    $helptext = file_get_contents($helpfile);
    $customContent .= "<br> " .MarkupToHTML($pagename,$helptext);

    $customContent .= "<br><button onclick=\"window.location.href='?action=browse';\">Back</button>";


                                                                // Output the standard PMWiki layout while replacing the main content
    PrintFmt($pagename, $PageStartFmt);                         // Print the header, sidebar, etc.
    echo $customContent;                                        // Replace main content with the management UI
    PrintFmt($pagename, $PageEndFmt);                           // Print the footer
    exit;
}


function HandleEditBib($pagename, $auth)                        #When a page is invoked with "action=editbib", just generate that page but be sure we pass the action name to it
{                                                               #That page can next test if $Action is set to 'editbib'
    
    if (!CondAuth($pagename, 'edit'))                           #Check if current user is allowed to edit at all
    {                                                           #If not, invoke the login mechanism of PMWiki; if login OK, continue displaying the editor for the Bib file
        RetrieveAuthPage($pagename, 'edit');
        Redirect($pagename . "?action=editbib");
        exit;
    }

    global $Action;                                             #If user can edit, show next the editor via (:editbib:)
    $Action = 'editbib';
    HandleBrowse($pagename);                                    #Display the current page again - we got the auth, so, just refresh the screen so to speak. But add "?action=editbib" so EditBibForm is called next
}


function EditBibForm($v)                                        #What (:editbib:) should actually expand do
{                                                               #v[] is an array of the type argv[] containing the arguments of (:editbib:)
    global $Bibtex_goback, $BibtexBibDir, $BibtexBibUrl, $PubDirUrl;
    
    $filename = trim($v[1]);                                    #Get the (:editbib:) argument telling the file we want to edit, trimming spaces
   
    $key = $_GET['key'];					#See if we invoked the editor with a specific Bibtex-entry in mind to edit
    if (!isset($key)) $key = "";
 
    $bibFile = "$BibtexBibDir/" . $filename;                    #Full path to the Bib file we want to edit
      
    if (!isset($Bibtex_goback))                                 #See where this edit-form was called from so we can go back there when editing done
       $Bibtex_goback = "?action=browse";
                                                                #Show an edit form that displays the Bib file;
                                                                #All needed styles are in pub/local.css
    
    $ret =  Keep("<div class='bibtex-edit-bib-form'>
                <div class='bibtex-edit-bib-form'>
                    <p><strong>Editing bibliography</strong></p><br>
                    <form id ='bibtext-form' method='post' class='bibtex-bib-form'>
                       <input type='hidden' name='filename' value='" . htmlspecialchars($filename). "'>         
                        <textarea id='bibtext' name='bibtext' class='bibtex-editor'> </textarea>
                        <div style='width: 100%; display: flex; justify-content: flex-start;'>
                            <input type='submit' value='Save' class 'bibtex-save-button'>
                            <input type='hidden' name='bibtext_compressed'>
                            <input type='hidden' name='goback' value = '$Bibtex_goback'>
                            <button type='reset' onclick=\"window.location.href='$Bibtex_goback';\">Cancel</button>
                        </div>
                    </form>
                </div>") . "\n";

    $file_url = $BibtexBibUrl . "/" . $filename; 
								#We pass the actual content via a JS script (faster)

    $ret .= Keep( 
      "<script> 
       document.addEventListener('DOMContentLoaded', function() 
       { 
         fetch('" . htmlspecialchars($file_url) . "' + '?t=' + new Date().getTime())    //The ?t= param is a unique value; forces browser cache invalidation
           .then(response => response.text())                              // Parse the response as text
           .then(text => {
              textarea = document.getElementById('bibtext');
              textarea.value = text;  // Set the file content into the textarea
              const keyword = '" . ($key) . "'; 
              if (keyword !== '')                                          //If we have $key set, this is the name of a Bibtex entry that we want 
              {                                                            //to 'bring in focus' in the text area (so we can easily edit it next).
                const index = text.indexOf(keyword);                       //To do this, we must compute the y scrolling-factor (in pixels).
                if (index != -1)                                           //To do that in turn, we create a fake div element having exactly
                {                                                          //the amount of text before the keyword; compute the height of this div;
                   const beforeText = text.slice(0, index);                //and use that height as scrolling factor on the edit box. 
                   const dummyDiv = document.createElement('div');
                   dummyDiv.style.visibility = 'hidden';
                   dummyDiv.style.position = 'absolute';
                   dummyDiv.style.whiteSpace = 'pre-wrap';
                   dummyDiv.style.wordWrap = 'break-word';
                   dummyDiv.style.width = textarea.clientWidth + 'px';
                   dummyDiv.style.font = window.getComputedStyle(textarea).font;
                   dummyDiv.style.lineHeight = window.getComputedStyle(textarea).lineHeight;
                   dummyDiv.textContent = beforeText;

                   document.body.appendChild(dummyDiv);
                   const scroll = dummyDiv.offsetHeight;                   //Get height of the dummy object
                   textarea.scrollTop = scroll;                            //Scroll text box by the height of the dummy object
                   document.body.removeChild(dummyDiv);                    //Delete the dummy object
                
                   textarea.focus();                                       //Highlight the keyword in the text box
                   textarea.setSelectionRange(index, index + keyword.length);
                   textarea.scrollTop = scroll;                            //Needed since some browsers mess up scrolling when highlighting
                }
              }
           });
       });
       document.querySelector('.bibtex-bib-form').addEventListener('submit', async function(e) 
       {                                                                   //Callback for the HTML form submission (when Save pressed)
          e.preventDefault();                                              //Prevent the default form submission since we want to do stuff below

          let bibText = document.getElementById('bibtext').value;          //Get the textarea value
          let compressed = pako.gzip(bibText);                             //Compress it using Pako
          let binString = Array.from(compressed, byte => String.fromCharCode(byte)).join(''); // Convert Uint8Array to binary string before base64 encoding
          let base64Data = btoa(binString);
          document.querySelector('input[name=\'bibtext_compressed\']').value = base64Data; //Store data to send in 'bibtex_compressed' HTML form field
          this.submit();                                                   // Finally submit the form to the server
       });
      </script>") . "\n";
  
    return $ret;

}
                
    
#---- Implem of the Bib-to-PMWiki conversion process ----------------------------------------------------------------------------------

$BibtexPdfLink = "pdf_logo.png";                                //Names of various image thumbnails used when constructing this webpage.
$BibtexUrlLink = "url_logo.png";                                //All these are supposed to be located in the Bibtex/ directory
$BibtexBibLink = "bibtex_logo.png";
$BibtexDoiLink = "doi_logo.png";
$BibtexThumbLink = "thumb_logo.jpg";
$BibtexGscholarLink = "gscholar_logo.png";
$BibtexCodeLink = "code_logo.png";
$BibtexAwardLink = "award_logo.png";
 

function BibQuery_callback($v)                                  //Generates markup for a (:bibquery:) keyword 
{
  global $BibtexBibDir;

  if (isset($_COOKIE['level_of_detail']))                       //If a level-of-detail was given via the UI, use it
     $lod = $_COOKIE['level_of_detail'];                        //NB: We have 3 caches here, one per level-of-detail
  else $lod = 'Full';

  $number = (isset($_COOKIE['bibtex_number']));   

  $sort =  isset($_COOKIE['bibtex_sort']) ? $_COOKIE['bibtex_sort'] : '';

  $group =  isset($_COOKIE['bibtex_group']) ? $_COOKIE['bibtex_group'] : '';

  $paramHash = md5("{$v[1]}_{$v[2]}_{$v[3]}_{$v[4]}_{$v[5]}_{$lod}_{$number}_{$sort}_{$group}");  //Do we have a cache for the current page with current params?

  $cacheFile = $BibtexBibDir . "/" . "cache_$paramHash.txt";

  $keywords = isset($_COOKIE['bibtex_keywords']) ? $_COOKIE['bibtex_keywords'] : "";
  $author = isset($_COOKIE['bibtex_author'])? $_COOKIE['bibtex_author'] : ""; 

  $ret = "";
 
  list($group,$grp_res) = SelectEntries($v[1], $v[2], $v[3], $v[4], $v[5]);            //Select entries to show from the bib file based on selection params
  if ($grp_res === null)
       return "%red%Cannot read BibTex file!";

                                                             //1. Add charts (if any asked for); we don't cache these since too many options
  if (isset($_COOKIE['show_charts']))                        //If showing charts was given via the UI, use it
       $show_charts = $_COOKIE['show_charts'];
  else $show_charts = 'None';

  if ($show_charts != 'None')
  {
      $groupCounts = [];
      foreach ($grp_res as $key => $entries) 
      {
        if ($key === "") continue;                            //Skip empty key //!!Not sure if correct: maybe add some 'other' keyname to this and report entries?
          $groupCounts[$key] = count($entries);
      }
      if (!empty($groupCounts))                               //Add code to create charts; done via the (:bibchart:) markup
      {
        $ret .= "!!Papers per " . printableSelector($group) . "\n";
        $ret .= '(:bibchart ' . json_encode($groupCounts) . ':)' . " \n";
      }
  }
                                                               //2. Render the selected entries (either from cache or else computed next)
  if ($keywords == "" && $author == "")                       //Specific queries on authors/keywords: We don't have a cache for that..
     if (file_exists($cacheFile))                             //Is there a valid cache? Then return its contents, we are done
       return $ret . file_get_contents($cacheFile);

  $output = AddBibEntries($grp_res);                          //Render selected bib entries into markup 
  
  if ($keywords == "" && $author == "")                       //Don't cache if we had specific author/keyword queries; we only cache general things
     file_put_contents($cacheFile, $output);                  //Cache that query result (mix of markup and HTML) for further use

   return $ret . $output;                                     //Return whatever we got (cached or computed)
}



function isValidDoi_rapidcheck($doi)                            //Cheap and dirty DOI check - looks only at basic syntax
{
    return substr($doi, 0, 3) === "10.";
}
                
function isValidDoi($doi)                                       //Checks if a DOI string is really valid (expensive)
{
    $url = "https://doi.org/" . urlencode($doi);

    $headers = @get_headers($url);                              //Suppresses warnings if URL is unreachable
    if ($headers && strpos($headers[0], '200') !== false)
    {
        return true; // DOI is valid
    }
    return false; // DOI is invalid
}
    
function extractGitHubUrl($text)
{
     $pattern = '~https://github\.com/[a-zA-Z0-9_-]+(/[a-zA-Z0-9._-]+(/[^ \t\r\n]*)?)?~';
     
     if (preg_match($pattern, $text, $matches))
        return $matches[0]; // The full GitHub URL found
     
     return null; // No valid GitHub URL found
}
                 
function extractUrl($text)
{
    $pattern = '~https?://[^\s]+~'; // Match http:// or https:// followed by non-whitespace characters

    if (preg_match($pattern, $text, $matches))
       return $matches[0]; // Return the first (and only) URL found
    
    return null; // No URL found
}
                 
function titleCase($title)                                      //Capitalizes text such as titles
{
    // List of words to keep lowercase unless at start or end
    $smallWords = [
        'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'if', 'in',
        'nor', 'of', 'on', 'or', 'so', 'the', 'to', 'up', 'yet', 'with'
    ];
    
    // Split the title into words
    $words = explode(' ', $title);
    
    foreach ($words as $key => $word)
    {
        // Lower-case small words if they are not at start or end
        if ($key !== 0 && $key !== count($words) - 1 && in_array($word, $smallWords))
            $words[$key] = strtolower($word);
        
        if ($key === 0 || $key === count($words) - 1 || !in_array($word, $smallWords))
        {
            $words[$key] = ucfirst($word);
        }
    }
                
    return implode(' ', $words);                                            //Join the words back into a string
}
    
function name_abbrev($name)                                                 //Abbreviate first name (possibly having several components, some already abbreviated, some not)
{                                                                           //in the form "initial1.initial2."  ...etc
    $nn = explode(" ", $name);
    
    $res = "";
    
    for ($i = 0; $i < count($nn); $i++)
    {
        $ni = $nn[$i];
        if ($ni!="")
        {
          if (strpos($ni,".")!=false) $res .= $ni;
          else
          {
              if (ctype_alnum($ni[0]) && ctype_upper($ni[0]))
                  $res .= $ni[0] . ".";
              else $res .= " " . $ni;
          }
        }

    }
    return $res;
}

function name_full($name)                                                   //Identity function, used with name_fmt() to return the authors' full names as in BibTex
{
    return trim($name);
}
    
function name_fmt($name, $name_abbrev) 
{
    $name = trim($name);

    // List of common particles in last names
    $particles = ['da','de','del','der','di','du','la','le','van','von','dos','den','el'];
    $suffixes = ['Jr','Sr','III','IV'];

    $first = '';
    $last = '';

    $parts = preg_split("/,/", $name);                                      // Check for comma: "Lastname, Firstname"
    if (count($parts) == 2) {
        $last = trim($parts[0]);
        $first = $name_abbrev(trim($parts[1]));
    } else {                                                                // "Firstname Lastname"
        $tokens = preg_split('/\s+/', $name);
        $num = count($tokens);

        if ($num == 1) {                                                    // Only one word: treat as first name
            $first = $name_abbrev($tokens[0]);
            $last = '';
        } else {                                                            // Assume last word is last name
            $last = array_pop($tokens);

            while ($tokens && in_array(end($tokens), $suffixes))            // Check for suffix 
                $last = array_pop($tokens) . ' ' . $last;
            
            while ($tokens && in_array(strtolower(end($tokens)), $particles)) 
                $last = array_pop($tokens) . ' ' . $last;

            $first = $name_abbrev(implode(' ', $tokens));
        }
    }

    return [$first, $last];
}

    
   


function evalExpr(string $expr, object $context)                            //Evaluates a PHP expression that is given as part of (:bibtexentry:) args to select, group, sort etc entries 
{                                                                           //$expr can contain $this which refers to the object $context.
    $expr = html_entity_decode($expr, ENT_QUOTES);                          //We use this helper func to ensure (as much as possible) that no unsafe PHP code can be executed
    $fn = function() use ($expr) 
    { return eval("return ($expr);"); }; 
    $fn = $fn->bindTo($context);                                            // now $this inside eval() = $context 
    return $fn(); 
}


class BibtexEntry                                                           //Superclass of all types of Bib entries. Records stuff that all Bib entries have.
{
    var $values = array();                                                  //All the (key,value) entries in this Bib entry, e.g., author="Foo", title="Bar", etc
    var $bibfile;                                                           //File that this entry comes from
    var $entryname;                                                         //Bibtex key given to the entry. E.g. $article{bla95...} will have name = bla95
    var $entrytype;                                                         //Bibtex entry type. E.g. $article{bla95...} will have entrytype = ARTICLE
                                                                            //Value is set by constructors of subclasses
    var $authors = array();                                                 //The names of all authors, nicely formatted as "F. G. Bar"
    var $authors_fullname = array();                                        //The names of all authors, but coming exactly as in the Bibtex file e.g. "Foo Goo Bar"
    var $authors_lastname = array();
    var $keywords = array();

    var $color;                                                             //Categorical color used when visualizing the Bibtex thumbnail; setr by ctors

    function __construct($bibfile, $entryname)                              //Ctor
    {
      $this->bibfile = $bibfile;
      $this->entryname = $entryname;
      $this->color = "#bfbfbf";
    }

    function processEntry()                                                     //Get authors from Bib entry; nicely format their names and write the formatted
    {                                                                           //names in the 'authors' data member array. These can be used later by clients.
      $this->authors = array();
      $this->authors_fullname = array();
      $this->authors_lastname = array();
      $this->keywords = array();

      $aut = $this->getFormat('AUTHOR');
      if ($aut == false) return false;
        
      $aut = explode(" and ", $aut);

      for ($i = 0; $i < count($aut); $i++)
      {
          $auth = name_fmt($aut[$i], 'name_abbrev');
          $this->authors[] = implode(" ", $auth); 

          $lastname = (strpos($auth[1], ' ') === false)? ucfirst(strtolower($auth[1])) : ucfirst(explode(' ', $auth[1])[0]) . substr($auth[1], strpos($auth[1], ' '));

          $this->authors_lastname[] = $lastname;

          $auth_name = name_fmt($aut[$i], 'name_full');
          $auth_name = implode(" ", $auth_name); 
          $auth_all_names = explode(" ", $auth_name);
          if (strlen($auth_all_names[0]) == 1)
              $auth_all_names[0] .= ".";

          $this->authors_fullname[] = implode(" ", $auth_all_names);
      }

      $this->keywords = explode(",", $this->getFormat('KEYWORDS'));
    }

    function getEditors()
    {
      $edi = $this->getFormat('EDITOR');
      if ($edi == FALSE) return FALSE;
        
      $edi = explode(" and ", $edi);

      $ret = "";

      for ($i = 0; $i < count($edi)-1; $i++)
        {
           $ret = $ret . implode(" ", name_fmt($edi[$i], 'name_abbrev'));
           $ret = $ret . ", ";
        }
        $ret = $ret . implode(" ", name_fmt($edi[count($edi)-1], 'name_abbrev'));
        
      return $ret;
    }
    
    function getName() {
      return $this->entryname;
    }

    function getTitle() {
      return titleCase($this->getFormat('TITLE'));
    }

    function getAbstract() {
      return $this->get('ABSTRACT');
    }

    function getPDF()                                                               //Get PDF either from local file or PDF-field in Bib record 
    {
      global $BibtexBibDir;

      $pdf_file = $BibtexBibDir . "/" . $this->entryname . ".pdf";
      if (file_exists($pdf_file)!=false) return $pdf_file;                          //Check if we have a locally stored PDF file

      $ret = $this->get('PDF');
      if ($ret)
	if(filter_var($ret, FILTER_VALIDATE_URL)==false)                            //Sanity check: some idiots list text in Bibtex
          $ret = "";                                                                //which is not a valid URL; if so, skip it
      return $ret;
    }

    function getComment() {
      return $this->get('COMMENT');
    }

    function getPages() {
      $pages = $this->get('PAGES');
      if ($pages)
      {
          $found = strpos($pages, "--");
          if ($found)
                return str_replace("--", "-", $pages);
          else
                return $pages;
      }
      return "";
    }

    function getPagesWithLabel()
    {
        $pages = $this->getPages();
        if ($pages)
        {
            if (is_numeric($pages[0]) && strpos($pages, "-")) 
                return "pages " . $pages;
            elseif (is_numeric($pages))
                return "page " . $pages;
        }
        return $pages;
    }
    
    function get($field)
    {
      if (strtolower($field) == 'entrytype') return trim($this->entrytype);
      $val = $this->values[$field];
      if ($val == FALSE) $val = $this->values[strtolower($field)];
      return trim($val);
    }


    function getFormat($field)
    {
      $ret = $this->get($field);
      if ($ret)
      {
        $ret = str_replace("{", "", $ret);
        $ret = str_replace("}", "", $ret);
      }
      return $ret;
    }

    function getCompleteEntryUrl()
    {
      global $pagename;

      $Bibfile = $this->bibfile;
      $Entryname = $this->entryname;

      if ($Entryname != "")
      {

        $fullUrl =  FmtPageName('$PageUrl', $pagename);
        $parsed = parse_url($fullUrl);
        $rootRelative = $parsed['path'];

        $BibtexCompleteEntriesUrl = $rootRelative . '?action=bibentry&bibfile=$Bibfile&bibref=$Entryname';

        $RetUrl = preg_replace('/\$Bibfile/', "$Bibfile", $BibtexCompleteEntriesUrl);
        $RetUrl = preg_replace('/\$Entryname/', "$Entryname", $RetUrl);
      }
      return $RetUrl;
    }

    function memberAuthors()                                    //Determines if this Bib entry is authored by one of the people in $BibMemberAuthors
    {
       global $BibMemberAuthors;

       $r = array_values(array_intersect((array)$this->authors_lastname,$BibMemberAuthors));
 
       return ($r) ? $r : ["Others"];

    }


    function getPreString()                                     //Generate markup code to show LANG, AUTHOR, YEAR, TITLE. These are entries which do not depend on the
    {                                                           //Bib-entry type (e.g. article, thesis) so we can do them generically
      global $pagename, $lastChar;
      $ret = "";

      if (count($this->authors))                                //Create authors' list
      {
          $alist = "";
          for($i=0; $i < count($this->authors)-1; $i++)
              $alist .= $this->authors[$i] . ", ";
          $alist .= $this->authors[count($this->authors)-1];
          
          $ret .= "'''" . $alist .  "'''";                      //Authors: rendered bold
      }

      $year = $this->get("YEAR");
      if ($year)
      {
          $ret = $ret . " (";
          $ret = $ret . $year . ") ";
      }

      if ($this->getTitle() != "")                                     //Title: rendered italic
      {
          $ret = $ret . "''";
          $ret = $ret . $this->getTitle();
          $ret = $ret . "''";
          $lastChar = $this->getTitle()[strlen($this->getTitle())-1];
      }

      return $ret;
  }


  function getPostString($dourl = true)                               //Called after getPreString. Generates markup to add the URL, PDF, DOI, and BibTeX fields.
  {                                                                   //As for getPreString, these are generic entries which don't depend on the Bib entry's type

      global $BibtexBibUrlShort, $RootPrefix, $BibtexUrlLink, $BibtexBibLink, $pagename, $BibtexBibDir, $BibtexPdfLink, $BibtexDoiLink, $BibtexGscholarLink, $BibtexCodeLink, $BibtexAwardLink;
            
      $ret = "";

      $ret .= xKeep("<br>");                                                         //All coming next are thumbnail logos, so, add them on a newline

      $imageUrl = $BibtexBibUrlShort . "/". $BibtexPdfLink;                              //This is code to add a PDF icon logo
      $pdfThumb = "<span><img src='$imageUrl' class='bibtex-small-thumb-cont'/></span>";

                
      $pdf_file = $BibtexBibDir . "/" . $this->entryname . ".pdf";                    //The name of this PDF is the Bibtex-entry-name . pdf
      if (file_exists($pdf_file)!=false)
      {
         $pdf_url = $BibtexBibUrlShort . "/". $this->entryname . ".pdf";     //1. File exists: map its name to an URL and add link "PDF" in markup
         $ret .= xKeep("<a href='" . $pdf_url . "'> $pdfThumb</a>");
      }
      else
      {
        $pdf = $this->get("PDF");
        if ($pdf)                                                                     //2. if Bibtex provided a PDF field:
        {      
             if (strpos($pdf, $RootPrefix) === 0)                                     //PDF field is file: strip prefix since not needed for web serving 
                $pdf = substr($pdf, strlen($RootPrefix));                            
             else                                                                     //PDF field is absolute URL: Hack: This code is needed since, in getSolePageEntry(), MarkupToHTML()
                $pdf = str_replace(":", "&#58;", $pdf);                               //will else screw up absolute "https://..etc" text (by its regex), aiming to 'fix' it as it thinks 
             
             $ret .= xKeep("<a href='" . $pdf . "'> $pdfThumb</a>");
        }
      }
      
      $imageUrl = $BibtexBibUrlShort . "/". $BibtexUrlLink;                              //This is code to add a URL icon logo
      $urlThumb = xKeep("<span><img src='$imageUrl' class='bibtex-small-thumb-cont'/></span>");

      $url = $this->get("URL");
      if ($url) 
      {
            $ret .= " [[" . $url . " | $urlThumb]]";
      }

      $imageUrl = $BibtexBibUrlShort . "/". $BibtexDoiLink;                               //This is code to add a DOI icon logo
      $doiThumb = xKeep("<span><img src='$imageUrl' class='bibtex-small-thumb-cont'/></span>");

      $doi = $this->get("DOI");
      if ($doi)
      {
        if (isValidDoi_rapidcheck($doi))
        //if (isValidDoi($doi))                                                       //Check the given DOI is valid indeed... Disabled since very slow
        {
           $doiUrl = "https://doi.org/" . urlencode($doi);
           $ret .= " [[" . $doiUrl . " | $doiThumb]]";
        }
      }
                 
      $code = $this->get("CODE");                                                     //Check if source-code is given for this entry; if a "code" field exists,
      if (empty($code))                                                               //simply assume its value contains a valid code-pointing URL
      {                                                                               //Else try to extract a GitHub-valid entry from all BibTex fields of this entry
             foreach ($this->values as $value)
             {
                 $url = extractGitHubUrl($value);
                 if ($url !== null)
                 {
                    $code = $url; break;                                              //Return the first found GitHub URL
                 }
             }
      }
      else
         $code = extractUrl($code);                                                   //Clean up the "code" field to get only an URL from it
                 
      if (!empty($code))                                                               //If we could locate any valid source code, show it
      {
          $imageUrl = $BibtexBibUrlShort . "/" . $BibtexCodeLink;
          $codeThumb = xKeep("<span><img src='$imageUrl' class='bibtex-small-thumb-cont'/></span>");
          $ret .= " [[" . $code . " | $codeThumb]]";
      }
      
      $award = $this->get("AWARD");                                                     //If the paper has an 'award' field, show the award icon
      if ($award)                                                                       //NB: The URL for this icon leads to the complete BibTex record;
      {                                                                                 //    Not ideal but I really don't know what else to link to here
          $imageUrl = $BibtexBibUrlShort . "/" . $BibtexAwardLink;
          $awardThumb = "<span><img src='$imageUrl' class='bibtex-small-thumb-cont'/></span>";
          $ret .= xKeep("<a href='" . $this->getCompleteEntryUrl() . "'>$awardThumb</a>");
      }
                 
      $borderColor = $this->color;                                                     //Get custom color for this Bib entry type

      $imageUrl = $BibtexBibUrlShort . "/" . $BibtexBibLink;                                //This adds a Bibtex icon logo
      $bibThumb = xKeep("<span><img src='$imageUrl' class='bibtex-small-thumb-cont' style='border: 3px solid $borderColor;'/></span>");

      if ($dourl && $this->entryname != "")
         $ret .= xKeep("<a href='" . $this->getCompleteEntryUrl() . "'>$bibThumb</a>");

      $imageUrl = $BibtexBibUrlShort . "/" . $BibtexGscholarLink;				            //Add an icon+link for a GScholar entry based on the paper's title
      $gscholarThumb = xKeep("<span><img src='$imageUrl' class='bibtex-small-thumb-cont'/></span>");
      
      $gscholarQuery = urlencode($this->get("TITLE"));                  		        //Format the URL as GScholar expects it
      $gscholarQuery = str_replace('%20', '+', $gscholarQuery);
      $encoded_gscholarUrl = "https://scholar.google.com/scholar?q=" . $gscholarQuery;
      $ret .= " [[" . $encoded_gscholarUrl . " | $gscholarThumb]]";

      return $ret;
    }

    function getBibEntry()                                                          //Dumps a (slightly reformatted) Bibtex entry as plain text
    {
      global $BibtexSilentFields;

      $ret = $ret . "@@" . $this->entrytype . " { " . $this->entryname . ",\\\\\n";

      foreach ($this->values as $key => $value)
      {
        if ($BibtexSilentFields && in_array($key, $BibtexSilentFields)) continue;
        $ret = $ret . "&nbsp;&nbsp;&nbsp;&nbsp;" . $key . " = { " . $value . " },\\\\\n";
      }

      $ret = $ret . "}@@\n";

      return $ret;
    }

    function getSolePageEntry()                                                     //Creates markup for the 'details' webpage which is shown when one
    {                                                                               //clicks on the "BibTeX" link in the main webpage
      $ret = "!" . "Reference: " . $this->entryname . "\n";
      
      $ret .= "\n!!!Summary\n";
      $ret .= $this->getRichSummary(false,'Full') . "\n";					    //Do not add BibTex icon here since we're anyways showing the BibTex record 

      $abstract = $this->getAbstract();
      if ($abstract)
      {
        $ret .= "\n!!!Abstract\n" . $abstract . "\n";
      }

      if (count($this->authors_fullname))
      {
            $ret .= "\n!!!Full author names\n";
          
            for($i=0; $i < count($this->authors_fullname)-1; $i++)
                $ret .= $this->authors_fullname[$i] . ", ";
            $ret .= $this->authors_fullname[count($this->authors_fullname)-1];
            
            $ret .= "\n";
      }

      if (count($this->keywords))
      {
            $ret .= "\n!!!Keywords\n";

            $ret .= implode(", ", array_map(fn($x) => "%bgcolor=silver% " . $x . "%%", $this->keywords)) . "\n";
      }
        
      $comment = $this->getComment();
      if ($comment)
      {
        $ret .= "\n!!!Comment\n" . $comment . "\n";
      }

      $ret .= "[[#" . $this->entryname . "Bib]]\n";
      $ret .= "\n!!!Bibtex entry\n" . $this->getBibEntry() . "\n";

      

      if (CondAuth('Main', 'edit'))                                                  //If user is authenticated, allow going to the management page for this entry
         $ret .= Keep("<button onclick=\"window.location.href='?action=managebib&selectedDir=" . $this->entryname . "_thumbs';\">Manage</button>");


      $ret .= Keep("<button onclick=\"window.location.href='?action=browse';\">Back</button>");

      return $ret;
    }



    function getRichSummary($show_bibtex_icon, $lod)  						//Create a 'rich' summary for this Bib record. This includes title, authors, year,
    {												                        //publisher etc (all fields from the record); depending on $lod, also add buttons
       $ret = $this->getSummary();								            //to access various such fields via URLs
                 
       $award = $this->get('AWARD');                                        //Add any mention of an award (in red), if one is given in Bibtex
       if ($award)
          $ret .= " (%red%" . $award . "%%)";
      
       $note =  $this->get("NOTE");
       if ($note)
          $ret .= " (" . $note . ")";
 
       if ($lod != 'Minimal')									            //$show_bibtex_icon separately controls if the BibTex button is to be shown or not
          $ret .= $this->getPostString($show_bibtex_icon); 
       return $ret;
    }
}

    
    
// *****************************
// PhDThesis
// *****************************
class PhdThesis extends BibtexEntry
{
    function __construct($bibfile, $entryname)
    {
      parent::__construct($bibfile, $entryname);
      $this->entrytype = "PHDTHESIS";
      $this->color = "#009999";
    }
    
    function getSummary()
    {
      global $lastChar;
        
      $ret = parent::getPreString();
      
      if (ctype_alnum($lastChar))
            $ret .= ".";
        
      $ret = $ret . " PhD thesis";
      $school = parent::get("INSTITUTION");
      if ($school)
      {
        $ret = $ret . ", ''" . $school . "''";
      }
      return $ret;
    }
  }

// *****************************
// MasterThesis
// *****************************
class MasterThesis extends BibtexEntry {
  function __construct($bibfile, $entryname)
  {
    parent::__construct($bibfile, $entryname);
    $this->entrytype = "MASTERSTHESIS";
    $this->color = "#99cc00";
  }

  function getSummary()
  {
    global $lastChar;
      
    $ret = parent::getPreString();

    if (ctype_alnum($lastChar))
          $ret .= ".";
        

    $ret = $ret . " Master's thesis";
    $school = parent::get("INSTITUTION");
    if ($school)
    {
      $ret = $ret . ", ''" . $school . "''";
    }
    return $ret;
  }
}

// *****************************
// TechReport
// *****************************
class TechReport extends BibtexEntry {
  function __construct($bibfile, $entryname)
  {
    parent::__construct($bibfile, $entryname);
    $this->entrytype = "TECHREPORT";
    $this->color = "#cc6699";
  }
    
  function getSummary()
  {
    global $lastChar;
      
    $ret = parent::getPreString();
    $type = parent::get("TYPE");
      
    if (ctype_alnum($lastChar))
          $ret .= ".";
        

    if ($type)
       $ret = $ret . " $type";
    else
       $ret = $ret . " Technical report";
    
    $number = parent::get("NUMBER");
    if ($number)
       $ret = $ret . " $number";
    $institution = parent::get("INSTITUTION");
    if ($institution)
    {
      $ret = $ret . ", " . $institution;
    }
    return $ret;
  }
}

// *****************************
// Article
// *****************************
class Article extends BibtexEntry
{
  function __construct($bibfile, $entryname)
  {
    parent::__construct($bibfile, $entryname);
    $this->entrytype = "ARTICLE";
    $this->color = "#33cc33";
  }
    
  function getSummary()
  {
    global $lastChar;
      
    $ret = parent::getPreString();
    $journal = parent::get("JOURNAL");
    if ($journal)
    {
      $journal = titleCase($journal);
        
      if (ctype_alnum($lastChar))
          $ret .= ".";
        
      $ret = $ret . " " . $journal;
      $volume = parent::get("VOLUME");
      if ($volume) {
        $ret = $ret . " " . $volume;
        $number = parent::get("NUMBER");
        if ($number) {
          $ret = $ret . "(" . $number . ")";
        }
        $pages = parent::getPages();
        if ($pages) {
          $ret = $ret . ":" . $pages;
        }
        $publisher = parent::get("PUBLISHER");
        if ($publisher) {
          $ret = $ret . $publisher;
        }
      }
    }
    return $ret;
  }
}

// *****************************
// InProceedings
// *****************************
class InProceedings extends BibtexEntry
{
    function __construct($bibfile, $entryname)
    {
      parent::__construct($bibfile, $entryname);
      $this->entrytype = "INPROCEEDINGS";
      $this->color = "#0066ff";
    }
    
    function getSummary()
    {
        global $lastChar;
        
        $ret = parent::getPreString();
        $booktitle = parent::get("BOOKTITLE");
        if ($booktitle)
        {
            if (ctype_alnum($lastChar))
                $ret .= ".";
              
            $ret = $ret . " In " . titleCase($booktitle);

            $address = parent::get("ADDRESS");
            if ($address)
            {
                if ($ret[strlen($ret) - 1] != '.')
                    $ret = $ret . ".";

                $ret = $ret . " " . $address;
            }
            
            $month = parent::get("MONTH");
            if ($month)
            {
                if ($ret[strlen($ret) - 1] != '.')
                    $ret = $ret . ",";

                $ret = $ret . " " . ucfirst($month);
            }
            
            $editor = parent::getEditors();
            if ($editor)
            {
                $ret = $ret . " (" . $editor .", Eds.)";
            }

            $publisher = parent::get("PUBLISHER");
            if ($publisher)
            {
                if ($ret[strlen($ret)-1] != '.')
                    $ret = $ret . ".";
                $ret = $ret . " " . $publisher;
            }

            $pages = $this->getPagesWithLabel();
            if ($pages)
            {
                if ($ret[strlen($ret) - 1] != ')')
                    $ret = $ret . ",";
                elseif ($pages[0] == 'p')
                    $pages[0] = 'P';

                $ret = $ret . " " . $pages;
            }

            $organization = parent::get("ORGANIZATION");
            if ($organization)
            {
                if ($ret[strlen($ret) - 1] != '.')
                    $ret = $ret . ", ";
                $ret = $ret . ". " . $organization;
            }
        }

        return $ret;
    }

}

// *****************************
// InCollection
// *****************************
class InCollection extends BibtexEntry {
  function __construct($bibfile, $entryname)
  {
    parent::__construct($bibfile, $entryname);
    $this->entrytype = "INCOLLECTION";
    $this->color = "#66ccff";
  }

  function getSummary()
  {
    global $lastChar;
      
    $ret = parent::getPreString();
    $booktitle = parent::get("BOOKTITLE");
    if ($booktitle)
    {
        if (ctype_alnum($lastChar))
            $ret .= ".";
        
        $ret = $ret . " In " . titleCase($booktitle) . "";

        $editor = parent::getEditors();
        if ($editor)
        {
          $ret = $ret . " (" . $editor .", Eds.)";
        }

        $pages = $this->getPagesWithLabel();
        if ($pages)
            $ret = $ret . ", " . $pages . ".";

        $publisher = parent::get("PUBLISHER");
        if ($publisher)
        {
            if ($ret[strlen($ret)-1] != '.')
                $ret = $ret . ". ";
            $ret = $ret . " " . $publisher;
        }
    }
    return $ret;

  }

}

// *****************************
// Book
// *****************************
class Book extends BibtexEntry
    {
    function __construct($bibfile, $entryname)
    {
      parent::__construct($bibfile, $entryname);
      $this->entrytype = "BOOK";
      $this->color = "#ff6600";
    }

    function getSummary()
    {

        $ret = $ret . parent::getPreString();
        
        $editor = $this->getEditors();
        if ($editor)
           $ret = $ret . " (" . $editor .", Eds.)";
        
        $publisher = parent::get("PUBLISHER");
        if ($publisher)
            $ret = $ret . " " . $publisher;

         $address = parent::get("ADDRESS");
         if ($address)
         {
             if ($ret && $ret[strlen($ret) - 1] != "." && $ret[strlen($ret) - 1] != "'")
                $ret = $ret . ",";
             $ret = $ret . " $address";
         }

        // Remove the point at the end of the string if only the title was provided
        if ($ret && $ret[strlen($ret) - 3] == '.')
            $ret = substr_replace($ret, "", strlen($ret) - 3, 1);
        
        return $ret;
    }
}

// *****************************
// InBook
// *****************************
class InBook extends BibtexEntry {
  function __construct($bibfile, $entryname)
  {
    parent::__construct($bibfile, $entryname);
    $this->entrytype = "INBOOK";
    $this->color = "#993300";
  }
    
    function getTitle()
    {
        return titleCase($this->getFormat('CHAPTER'));
    }
    function getSummary()
    {
        global $lastChar;
        
        $ret = $this->getPreString();
        $booktitle = parent::get("TITLE");
        if ($booktitle)
        {
            if (ctype_alnum($lastChar))
                $ret .= ".";
            
            $ret = $ret . " In " . titleCase($booktitle) . ".";
           
            $editor = parent::getEditors();
            if ($editor)
            {
                if ($ret[strlen($ret)-1] != '.')
                    $ret = $ret . ".";

                $ret = $ret . " (" . $editor .", Eds.)";
            }

            $address = parent::get("ADDRESS");
            if ($address)
            {
                if ($ret[strlen($ret) - 1] != '.')
                    $ret = $ret . ".";

                $ret = $ret . " " . $address;
            }
            
            $publisher = parent::get("PUBLISHER");
            if ($publisher)
            {
                if ($ret[strlen($ret)-1] != ',')
                    $ret = $ret . ",";
                $ret = $ret . " " . $publisher;
            }

            $pages = $this->getPagesWithLabel();
            if ($pages)
            {
                if ($ret[strlen($ret) - 1] != ')')
                    $ret = $ret . ",";
                elseif ($pages[0] == 'p')
                    $pages[0] = 'P';

                $ret = $ret . " " . $pages;
            }

            $organization = parent::get("ORGANIZATION");
            if ($organization)
            {
                if ($ret[strlen($ret) - 1] != ')')
                    $ret = $ret . ", ";
                $ret = $ret . ". " . $organization;
            }
        }

        return $ret;
    }

}

// *****************************
// Proceedings
// *****************************
class Proceedings extends BibtexEntry {
      function __construct($bibfile, $entryname)
      {
         parent::__construct($bibfile, $entryname);
         $this->entrytype = "PROCEEDINGS";
         $this->color = "#cc00cc";
      }
    
      function getSummary()
      {
         $ret = parent::getPreString();
         $editor = parent::getEditors();
         if ($editor)
             $ret = $ret . " (" . $editor .", Eds.)";

         $volume = parent::get("VOLUME");
         if ($volume)
         {
            $ret = $ret . "volume " . $volume;
            $series = parent::get("SERIES");
            if ( $series != "" )
               $ret = $ret . " of ''$series''";
         }
         $address = parent::get("ADDRESS");
         if ($address)
            $ret = $ret . ", $address";
         $orga = parent::get("ORGANIZATION");
         if ($orga)
            $ret = $ret . ", $orga";
         $publisher = parent::get("PUBLISHER");
         if ($publisher)
            $ret = $ret . ", $publisher";
         return $ret;
      }
}

// *****************************
// Misc
// *****************************
class Misc extends BibtexEntry {
  function __construct($bibfile, $entryname)
  {
    parent::__construct($bibfile, $entryname);
    $this->entrytype = "MISC";
    $this->color = "#999966";
  }
    
  function getSummary()
  {
    global $lastChar;
      
    $ret = parent::getPreString();
    
    $howpublished = parent::get("HOWPUBLISHED");
    if ($howpublished)
    {
        if (ctype_alnum($lastChar))
            $ret .= ".";
        
        $ret .=  " " . $howpublished;
    }
      
    return $ret;
  }
}

    
function makeThumb($value)
{
        global $BibtexBibUrlShort, $RootPrefix, $BibtexBibUrl, $BibtexBibDir, $BibtexThumbLink;

        $img_files = [];                                                //Collects names of all thumbnails for this entry

        $thumbs_dir = $BibtexBibDir . "/" . $value->entryname . "_thumbs";
        if (!is_dir($thumbs_dir))                                       //1. Make in any case the _thumbs dir if not existing
          mkdir($thumbs_dir,0775);

        $processed_log = $thumbs_dir . "/processed.log";

        if (!file_exists($processed_log))                               //2. See if we already ran the PDF extractor. If not, run it now
        {
          $pdf_file = $value->getPDF();                                 //Get PDF (either local or via PDF field in Bib record) 
          if ($pdf_file)                                                //If we got any PDF, extract max 10 thumbnails from it into the thumbs dir
                                                                        //This is slow if not already done
             PDFToThumbnails::computeThumbnails($pdf_file, $value->entryname, $BibtexBibDir, 10);
          touch($processed_log);                                        //Mark thios thumbs dir as already processed (from its PDF)
        }

        $all_jpgs = glob($thumbs_dir . "/" . '*.jpg') ?: [];             //3. Get all JPG images in _thumbs dir. These can be extracted thumbs,
                                                                         //   user-supplied custom thumbs, or _raw_image*jpg temp files from the extractor
                                                                         //   Keep only the valid ones for display
        $img_files = preg_grep('#/_raw[^/]*\.jpg$#', $all_jpgs, PREG_GREP_INVERT);
        
        $img_files = array_map(function($f) {
           return basename(dirname($f)) . '/' . basename($f);
        }, $img_files);

        if (count($img_files)==0)                                       //4. If could not get any thumb images, use a default image
           $img_files[] = $BibtexThumbLink;

        $result_link = "";                                              //Try now to make the best possible link for the thumbnail: local PDF from name-> PDF from BibTex -> URL to DOI

        if ($result_link=="")                                           //1. See if we have a locally cached PDF for this entry - if so, use it
        {
            $pdf_file = $BibtexBibDir . "/" . $value->entryname . ".pdf";
 
            if (file_exists($pdf_file)!=false)
            {
                $pdf_url = $BibtexBibUrl . "/". $value->entryname . ".pdf";
                $result_link = $pdf_url;
            }
        }

        if ($result_link=="")                                           //2. If there's no local PDF file, see if we have a "PDF" entry in the Bib record
        {
            $pdf = $value->get("PDF");
            if ($pdf)                                                   //If there's a provided PDF field in Bib, link thumbnail to it
            {
                $result_link = $pdf;
            }
        }

        if ($result_link=="")                                           //3. If no PDF found so far, see if we have a URL in the Bib field. If so, link thumbnail to it
        {
            $url = $value->get("URL");
            if ($url)                                                   //Check the URL is valid syntactically
            {
                $result_link = $url;
            }
        }

        if ($result_link=="")                                           //4. If no URL or PDF, see if we have a DOI; if so, link thumbnail to it
        {
            $doi = $value->get("DOI");
            if ($doi)
            {
              if (isValidDoi_rapidcheck($doi))
              //if (isValidDoi($doi))                                   //Check the given DOI is valid indeed... Disabled since very slow
                $result_link = "https://doi.org/" . urlencode($doi);
            }
        }

        $imageUrl = $BibtexBibUrlShort . "/". $img_files[0];                 //Generate actual HTML code to display the image since we're using
                                                                        //next a special CSS style to cut/resize the image to create thumbnails

        $thumb_container_name = "thumbC-" . $value->entryname;   //We'll create a div container to store a 'data-images' attribute with names of all thumbnails

        $thumb_images = implode(',', array_map(fn($img) => $BibtexBibUrlShort . "/". $img, $img_files));
                                                                        //Collect names of all thumb-imgs for this to pass them to JS for thumbnail animation
        $thumb_name = "thumbnail-" . $value->entryname;                 //HTML ID for the actual thumbnail

        $thumb_code = "<div id='$thumb_container_name' class='bib-thumb-c' data-images='$thumb_images'> <img id ='$thumb_name' class='bibtex-img-thumb-cont' src='$imageUrl' loading='lazy'/></div>"; 
                                                                        //Create the container and the thumbnail inside it

        if ($result_link)                                               //Did we get some valid link for the thumbnail at all? Then use it; else, just show the thumbnail
        {                                                               //This makes clicking on the container follow $result_link
            if (strpos($result_link, $RootPrefix) === 0)
             {
                $result_link = substr($result_link, strlen($RootPrefix));
                $thumbnail .= xKeep("<a href='" . $result_link . "'> $thumb_code</a>");
             }
             else
                $thumbnail .= " [[ $result_link | $thumb_code]] ";
        }
        else
            $thumbnail =  xKeep($thumb_code);                           //Simply pass the code for creating the thumbnail without a hyperlink to it  
                                                                        //since we don't have a hyperlink
        return $thumbnail;
}






function BibChart_callback($v)                                          //Generates html from (:bibchart:) markup to show stats using D3
{
  global $PubDirUrl;

  if (!isset($v[1])) return "";                                         //No data passed? Nothing to display 

  $ret = "<div id='bibtex-stats-chart'></div>
          <script>
             drawBarChart('bibtex-stats-chart', $v[1]);
          </script>";

  return $ret;
}


function printableSelector($php_expr)                                                        //Makes a human-readable expression from a PHP expression that is 
{                                                                                            //used as one of the args of (: bibtexquery :). Typical args for this
    $php_expr = html_entity_decode($php_expr);                                               //are of the form $this->fieldname or $this->get('keyname').
    $ret = '';                                                                               //We want to return simply fieldname or keyname.
    if (preg_match('/\\$this->get\\([\'"]([^\'"]+)[\'"]\\)/', $php_expr, $m)) {              //Used to show in the webpage what the selection actually used.  
        $ret = $m[1];
    }
    else
    if (preg_match('/\\$this->([a-zA-Z0-9_]+)/', $php_expr, $m)) {
        $ret = $m[1];
    }
    
    return ucfirst(strtolower(str_replace('_',' ',$ret)));
}


function SelectEntries($file, $cond, $group, $sort, $max)                                //Select bib entries from $file given criteria; return selected/grouped results 
{
    global $BibEntries;

    $file  = trim($file);                                                                       //Get arguments
    $cond  = trim($cond);
    $sort  = trim($sort);
    $group = trim($group);

    if (!$BibEntries[$file])                                                                    //Make sure we loaded the Bibtex file
        if (!ReadBibFile($file))
            return [$group,null];

    $bibentries = $BibEntries[$file];                                                           //All entries from the current Bibtex file
    $res = array();                                                                             //Gathers all Bib entries which pass the filter/sorting

    if ($cond == '') $cond = 'true';                                                            //1. Process condition (filter):

    if (isset($_COOKIE['bibtex_author']))                                                       //If sorting criterion given via the UI,
    {                                                                                           //make it override the one given by bibquery:
       $user_author = $_COOKIE['bibtex_author'];
       $cond = $cond . " && stripos(\$this->get('AUTHOR'),'$user_author')!==false";
    }

    if (isset($_COOKIE['bibtex_keywords']))                                                     //If keywords given via the UI,
    {                                                                                           //add them to the filter
       $user_keywords = $_COOKIE['bibtex_keywords'];
       $cond = $cond . " && stripos(\$this->get('KEYWORDS'),'$user_keywords')!==false";
    }

    foreach ($bibentries as $key => $value)                                                     //Select entries matching 'cond'
        if (evalExpr($cond,$value)) $res[] = $value;

                                                                                                //2. Process sorting criterion
    if (isset($_COOKIE['bibtex_sort']))                                                         //If sorting criterion given via the UI,
    {                                                                                           //make this override the one in (:bibquery:)
       $user_sort = $_COOKIE['bibtex_sort'];
       if ($user_sort == 'sort_author')
          $sort = '$this->authors_lastname[0]';
       else if ($user_sort == 'sort_type')
          $sort = '$this->entrytype';
       else if ($user_sort == 'sort_year')
          $sort = '!$this->get(\'YEAR\')';                                                      //Prepend a '!' to enforce inverse-chrono by-year sorting
    }

    if ($sort != '')                                                                            //First see if one was given by the (:bibquery:) spec
    {   
        if ($sort[0] == '!')                                                                    //If sort starts by !, revert order (may not work)
        { $sort_reverse = true; $sort = substr($sort, 1); }
        else $sort_reverse = false;
    }

    if (isset($_COOKIE['bibtex_group']))                                                        //If grouping criterion given via the UI,
    {                                                                                           //make this override the one in (:bibquery:)
       $user_group = $_COOKIE['bibtex_group'];
       if ($user_group == 'group_type')
          $group = '$this->entrytype';
       else if ($user_group == 'group_year')
          $group = '!$this->get(\'YEAR\')';
       else if ($user_group == 'group_author')
          $group = '$this->authors_lastname';
       else if ($user_group == 'group_members')
          $group = '$this->memberAuthors()'; 
    }

   if ($group != '')                                                                           //3. Process grouping criterion
    {
       if ($group[0] == '!')                                                                    //If group starts by !, revert order of groups
        { $grp_reverse = true; $group = substr($group, 1); }
        else $grp_reverse = false;
    }

    $grp_res = [];                                                                              //Group elements by given criterion (if any)
    foreach ($res as $element)                                                                  //If no criterion, group them under a dummy key
    {
      $key = ($group!='')? evalExpr($group,$element) : "";                                      //Criterion to evaluate (if any)
      $key = $key ?? "";                                                                        //Traps cases where the criterion evaluates to NULL
                                                                                                //Evaluating the criterion can return a single value (e.g. year)
      if (is_array($key))                                                                       //or an array (e.g. author names). If we have an array, simply
      {                                                                                         //use all its elements as values to group by
         foreach ($key as $key_elem)                                                            //NB: this can replicate Bib elements which will appear multiple times
           $grp_res[$key_elem][] = $element;                                                    //    under different group-by values (which is the desired result)
      }
      else
         $grp_res[$key][] = $element;
    }



    if ($sort!='')                                                                              //Execute in-group sorting (if we have any criterion) 
    { 
      foreach ($grp_res as $key => $entries)                                                
      {
        $keys = [];

        foreach ($entries as $i => $e)
          $keys[$i] = evalExpr($sort, $e);

        array_multisort($keys,$sort_reverse ? SORT_DESC : SORT_ASC, $entries);
        $grp_res[$key] = $entries;       
      }
    }

    if ($grp_reverse)                                                                           //4. Sort the groups
       krsort($grp_res);
    else
       ksort($grp_res);

    if (isset($grp_res['Others']))                                                              //5. If we use the key 'Others' (from group by members), make sure it comes last
      $grp_res = array_diff_key($grp_res, ['Others' => true]) + ['Others' => $grp_res['Others']];

    return [$group,$grp_res];
}


function AddBibEntries($grp_res)                                                                //Generates markup to show entries selected/grouped in $grp_res
{
    $ret = "";                                                                                  //Start adding the webpage's content

    $tot_entries = array_sum(array_map('count', $grp_res));                                     //Count the total #entries we will display
                 
    $add_numbers = (isset($_COOKIE['bibtex_number']));                                          //See if we want to number the entries (decreasingly)
                 
    if (isset($_COOKIE['level_of_detail']))                                                     //If a level-of-detail was given via the UI, use it
       $lod = $_COOKIE['level_of_detail'];
    else $lod = 'Full';

    $num_entries = 0;									        //Get max #entries we want to dump (as integer),
    $max_entries = ($max != '')? (int)$max : 100000;					        //if any provided

    foreach ($grp_res as $key => $entries)                                                      //add Bib entries for all groups
    {
        if ($key!="") 
        {
           $key = (strpos($key, ' ') === false)? ucfirst(strtolower($key)) : $key;
           $ret .= "!" . $key . "\n";		                                                //Add group title i.e. its key value (if any available)
        }
        $ret .= "(:table cellspacing=0 bgcolor=#efefef :) " . "\n";   		                //Add all group entries in a table
        foreach($entries as $value)
        {
          if ($lod=='Full')						                        //First cell: thumbnails (if LOD is 'Full')
          {
             $thumbnail = makeThumb($value);                                             //Build all the complex code for managing the thumbnail
             $ret .= "(:cellnr width=20%:) %center% %width=20pct% $thumbnail \n";
             $ret .=  "(:cell:) "; 
          }
          else $ret .= "(:cellnr:) ";
                 
          if ($add_numbers) $ret .= "'''". $tot_entries - $num_entries. "'''. ";         //If we want to number entries: do that

          $ret .= $value->getRichSummary(true,$lod) . "\n";                              //second cell: summary (authors, year, title, various other logos)
  
          $num_entries++;								                                 //stop dumping entries when we reached the max
          if ($num_entries == $max_entries) break;
        }
        $ret .= "(:tableend:)\n";

        if ($num_entries == $max_entries) break;					                     //stop dumping entries when we reached the max
    }
    
    return $ret;
}
        

function HandleBibEntry($pagename)                                                //Generates HTML code for $pagename to display a single Bibtex entry.
{                                                                                 //The entry and the Bibtex file it's in are passed via &... args in the URL
  global $PageStartFmt, $PageEndFmt, $BibtexKeepFlag;
  $bibfile = $_GET['bibfile'];
  $bibref = $_GET['bibref'];

  $bibentry = GetEntry($bibfile, $bibref);                                        //Get the actual Bibtex entry from our database                  
  if ($bibentry == false) $content = "%red%Invalid BibTex Entry: [" . $bibfile . ", " . $bibref . "]!";
  else
  {                                                                               //Generates the code for a single-page entry.
      $BibtexKeepFlag = true;                                                     //We need to enable PMWiki's Keep() here since the code can contain  
      $content =  $bibentry->getSolePageEntry();                                  //HTML and markup and we next run MarkupToHTML() explicitly to       
      $BibtexKeepFlag = false;                                                    //expand that markup...
  }

  $content = MarkupToHTML($pagename,$content);                                     //Generate HTML code to display all details of that Bibtex entry 
  $bibtexFmt = array(&$PageStartFmt, &$content, &$PageEndFmt);
  PrintFmt($pagename,$bibtexFmt);
}


function GetEntry($bib, $ref)
{
    global $BibEntries;
    $ref = trim($ref);
    $bib = trim($bib);
    

    $bibtable = $BibEntries[$bib];


    if (is_null($bibtable))
    {
      $result = ReadBibFile($bib);
      $bibtable = $BibEntries[$bib];
    }
    
    reset($bibtable);

    foreach ($bibtable as $key => $value) {
        if ($value->getName() == $ref) {
            $bibref = $value;
            break;
        }
    }

    if ($bibref == false)
      return false;
    return $bibref;
}


function GetBibtexKeySafename($key) 
{
    // Replace all characters that are NOT a-z, A-Z, 0-9, underscore or dash with an underscore
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
}


function ParseEntries($fname, $entries)                                             //Given array 'entries' of text-blocks, one per Bib entry, creates PHP objects
{                                                                                   //mapping each such entry, depending on their types.
   global $BibEntries;                                                              //Finally, populates the global BibEntries array with all these objects.
   $nb_entries = count($entries[0]);

   $bibfileentry = array();                                                         //This will gather all the parsed Bib entries (with their respective fields)
    
   for ($i = 0 ; $i < $nb_entries ; ++$i)                                           //Process each Bib entry received:
   {
      $entrytype = strtoupper($entries[1][$i]);                                     //Type of entry, e.g. 'article'
      $entryname = GetBibtexKeySafename($entries[2][$i]);                           //Name of entry, e.g. 'foobar95'
                                                                                    //Sanitize the name so we can next make a dir from it (for thumbs)
      if      ($entrytype == "ARTICLE") $entry = new Article($fname, $entryname);
      else if ($entrytype == "INPROCEEDINGS") $entry = new InProceedings($fname, $entryname);
      else if ($entrytype == "PHDTHESIS") $entry = new PhdThesis($fname, $entryname);
      else if ($entrytype == "MASTERSTHESIS") $entry = new MasterThesis($fname, $entryname);
      else if ($entrytype == "INCOLLECTION") $entry = new InCollection($fname, $entryname);
      else if ($entrytype == "BOOK") $entry = new Book($fname, $entryname);
      else if ($entrytype == "INBOOK") $entry = new InBook($fname, $entryname);
      else if ($entrytype == "TECHREPORT") $entry = new TechReport($fname, $entryname);
      else if ($entrytype == "PROCEEDINGS") $entry = new Proceedings($fname, $entryname);
      else     $entry = new Misc($fname, $entryname);

      preg_match_all("/(\w+)\s*=\s*([^¶]+)¶?/", $entries[3][$i], $all_keys);        //Split Bib text for the entry into individual key=value entries
      
      for ($j = 0 ; $j < count($all_keys[0]) ; $j++)                                //Gets all fields of the Bib record (e.g. author, year) and adds them as
      {                                                                             // (key,value) pairs to the 'value' field of the created objects
        $key = strtoupper($all_keys[1][$j]);
        
        if ($key=="FILE") continue;                                                 //Skip Bib "file" entries since these point to local resources we anyways
                                                                                    //don't have access to and should not be publicly displayed
        $value = $all_keys[2][$j];
        
        $value = preg_replace('/^\s*{(.*)}\s*$/', '\1', $value);                    //Remove the leading and ending braces or quotes if any
        
        $value = preg_replace('/^\s*"(.*)"\s*$/', '\1', $value);                    //TODO: only run this regexp if the former didn't match

        $value = trim($value);                                                      //Remove preceding and trailing spaces since useless
        
        $value = preg_replace('/\s+/', ' ', $value);                                //Replace newlines with spaces
          
        $value = str_replace("{\~a}", "&atilde;", $value);                          //Encode special Latex characters into HTML equivalents
        $value = str_replace("{\'a}", "&aacute;", $value);
        $value = str_replace("{\'A}", "&Aacute;", $value);
        $value = str_replace('{\"a}', "&auml;", $value);
        $value = str_replace('{\"A}', "&Auml;", $value);
        $value = str_replace("{\'c}", "&cacute;", $value);
        $value = str_replace("{\'C}", "&Cacute;", $value);
        $value = str_replace("{\c c}", "&ccedil;", $value);
        $value = str_replace('{\v c}', "&ccaron;", $value);
        $value = str_replace('{\v C}', "&Ccaron;", $value);
        $value = str_replace('{\v D}', "&Dcaron;", $value);
        $value = str_replace("{\'E}", "&Eacute;", $value);
        $value = str_replace("{\'e}", "&eacute;", $value);
        $value = str_replace('{\"e}', "&euml;", $value);
        $value = str_replace("{\`e}", "&egrave;", $value);
        $value = str_replace("{\'i}", "&iacute;", $value);
        $value = str_replace("{\^i}", "&icirc;", $value);
        $value = str_replace("{\i}", "&imath;", $value);
        $value = str_replace("{\l}", "&lstrok;", $value);
        $value = str_replace("{\L}", "&Lstrok;", $value);
        $value = str_replace("{\~n}", "&ntilde;", $value);
        $value = str_replace("{\'o}", "&oacute;", $value);
        $value = str_replace("{\~o}", "&otilde;", $value);
        $value = str_replace("{\'O}", "&Oacute;", $value);
        $value = str_replace('{\"o}', "&ouml;", $value);
        $value = str_replace('{\"O}', "&Ouml;", $value);
        $value = str_replace('{\o}', "&oslash;", $value);
        $value = str_replace('{\'r}', "&racute;", $value);
        $value = str_replace('{\v r}', "&rcaron;", $value);
        $value = str_replace('{\v s}', "&scaron;", $value);
        $value = str_replace('{\v S}', "&Scaron;", $value);
        $value = str_replace('{\c s}', "&scedil;", $value);
        $value = str_replace("{\'u}", "&uacute;", $value);
        $value = str_replace("{\'U}", "&Uacute;", $value);
        $value = str_replace('{\"u}', "&uuml;", $value);
        $value = str_replace('{\"U}', "&Uuml;", $value);
        $value = str_replace('{\v z}', "&zcaron;", $value);
        $value = str_replace('{\v Z}', "&Zcaron;", $value);
          
        $value = str_replace("\&", "&", $value);                                    //Do some other (brutal) cleanup of the Bibtex text

        $value = preg_replace_callback(                                             //In math mode $...$, replace braces by HTML brace chars
           '/\$.*?\$/s',                                                            //This way they stay there but don't get interpreted by PMWiki as markup
           function($m){ return str_replace(['{','}'], ['&#123;','&#125;'], $m[0]); },
           $value);

        $value = str_replace("{","", $value);                                       //Strip ALL other braces (not in math mode). Needed otherwise 
        $value = str_replace("}","", $value);                                       //PMWiki interprets them as markup
                //!! $value = str_replace("~"," ", $value);                         //Cannot really do this since '~' may occur in URL's...
        $value = str_replace("\\textemdash","-", $value);
        $value = str_replace("\\textendash","-", $value);
        $value = str_replace("\\textquoteleft","'",$value);
        $value = str_replace("\\textquoteright","'",$value);
        $value = str_replace("\\textless","<",$value);
        $value = str_replace("\\textgreater",">",$value);
        $value = str_replace("\\textpm","&plusmn;",$value);

          
        $value = rtrim($value, ".");                                                //Some idiots leave punctuation signs at the end of Bibtex entries;
        $value = rtrim($value, ":");                                                //These are useless, remove them
        $value = rtrim($value, ";");

        

        $entry->values[$key] = $value;                                              //Add one more parsed record to this BibtexEntry
      }
      
       $entry->processEntry();                                                      //Now that the entry is parsed, extract the nicely-formatted names of all authors
                                                                                    //Store them in the entry for future use
       $bibfileentry[] = $entry;                                                    //One more parsed Bib entry for the entire file
   }

   $BibEntries[$fname] = $bibfileentry;                                             //Store in this global all parsed Bib entries for this file
}

    
    


function ParseBib($bib_file, $bib_file_string)                                          //Given Bibtex file loaded in string, splits the file into text bits, once per Bib record
{                                                                                       //Then calls ParseEntries to parse each such record
   //First we do an ugly trick to replace the first '{' and the last '}' of each bib entry by another special char (to help with regexp)
   $count=0;
   for ($i = 0 ; $i < strlen($bib_file_string) ; $i++)
   {
      if ($bib_file_string[$i] == '{')
      {
         if ($count==0)
            $bib_file_string[$i] = '¤';
         $count++;
      }
      else if ($bib_file_string[$i] == '}')
      {
         $count--;
         if ($count==0)
            $bib_file_string[$i] = '¤';
      }
      else if ($bib_file_string[$i] == ',' && $count == 1)
      {
        $bib_file_string[$i] = '¶';
      }
      else if ($bib_file_string[$i] == "\r" && $count == 1)
        $bib_file_string[$i] = '¶';
   }

   $bib_file_string = preg_replace("/¶¶/", "¶", $bib_file_string);

   $nb_bibentry = preg_match_all("/@(\w+)\s*¤\s*([^¶]*)¶([^¤]*)¤/", $bib_file_string, $matches);
   ParseEntries($bib_file, $matches);                                                  //Now parse the file content
}




function ReadBibFile($bib_file)                                                        //Loads given Bibtex file into the entry $BibEntries[$bib_file].
{
    global $BibtexBibDir, $pagename, $BibEntries;

    $bib_file_qual = $BibtexBibDir . "/" . $bib_file;				       //Fully qual'd name of Bibtex file
    $cache_file = $bib_file_qual . '.cache';                                           //Already-parsed Bibtex file, cached for speed

    if (file_exists($cache_file) && filemtime($cache_file) > filemtime($bib_file_qual)) //Cache exists and valid (newer than Bibtex file):
    {  											//Load from it, faster than re-parsing the Bibtex file
        $BibEntries[$bib_file] = unserialize(file_get_contents($cache_file));
        return true;
    }
    else 									       //Cache does not exist or stale: must parse the Bibtex file
    {
        if (file_exists($bib_file_qual))
        {
            $f = fopen($bib_file_qual, "r");
            if ($f) 
            {  
                $bib_file_string = "";
                while (!feof($f))
                    $bib_file_string = $bib_file_string . fgets($f, 1024);             //Load Bibtex file into a string

                $bib_file_string = preg_replace("/\n/", "", $bib_file_string);
                ParseBib($bib_file, $bib_file_string);				       //Parse Bibtex file loaded into a string

                file_put_contents($cache_file, serialize($BibEntries[$bib_file]));     //Create cache from $BibEntries[$bib_file]
                return true;                                                           //so we can quickly load it in the future
            }
            return false;
        }
        return false;
    }
}



// Code below is needed so this script responds to client POST requests (initiated by UI elements)
//
//





$UploadExts['bib'] = 'text/plain';
$UploadExts['pdf'] = 'application/pdf';

?>
