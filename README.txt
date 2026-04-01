#
#  bibtexref.php:        A PMWiki cookbook for displaying a wiki page showing nicely formatted references from a given BibTex file
#
#
#  1. Installation
#  -copy this dir (BibTexRef) in the wiki's cookbook/ directory
#  -add content from local.css to pub/css/local.css (if latter doesn't exist, create it)
#  -copy the pako/ dir to pub/pako
#  -copy the bibtexref/ dir to pub/bibtexref
#  -create dir Bibtex/ in uploads/ with the same permissions as uploads/ (see PMWiki for details)
#  -copy all *logo.jpg files from here to Bibtex/
#  -add to local/config.php
#           include_once("$FarmD/cookbook/BibTexRef/bibtexref.php");
#
#  2. Usage
#   -copy the Bibtex file file.bib you want to show to the Bibtex/ directory.
#   -in the wikipage where you want to show entries from this file, start creating the publication list by
#
#           bibinit: [gui][members] 
#
#    Set [gui] if you want to show buttons for sorting/selecting publications (see below).
#    Set [members] to a list of author lastnames, e.g. "Smith,"Jones","Abel", if you want next to allow grouping papers by authors == members of a research team.
#     
#
#    Next specify which entries you want to show and how to group/sort them.
#    To e.g. show entries having author Smith, grouped by increasing year, sorted by pub type:
#
#           bibtexquery:[file.bib][strpos($this->get('AUTHOR'),'Smith')!==false][$this->get('YEAR')][$this->entrytype][]
#
#    To show all Bibtex entries grouped by year:
#           bibtexquery:[test.bib][][!$this->get('YEAR')][][]  
#
#    The syntax is bibtexquery:[file.bib][selection_condition][group_by_expr][sort_on_expr][max_entries]
#    All parameters are mandatory. To skip setting a parameter, set it to [].
#    See point 5 below for a full explanation of the parameters.
#
#   -add in the wikipage where you want to show the Bibtex editor for ?action=editbib (usually same where the Bibtex file is shown), best at top:
#
#           (:if equal {$Action} editbib:)
#           (:editbib file.bib:)
#           (:ifend:)
#    When this page is invoked with ?action=editbib, it shows the Bibtex editor (asks for wiki login if needed)
#    
#   -add in the same wikipage, best at top:
#
#           (:if equal {$Action} managebib:)
#           (:managebib file.bib:)
#           (:ifend:)
#    When this page is invoked with ?action=managebib, an interface is popped up to manage various internal settings; just try it, it's self explaining.
#
#    If you want to show a gallery of N dynamically-updating thumbnails from papers, add
#
#           (:bibthumbsgallery N:)
#
#    to that place in the wiki page. All images uploaded by users are used plus a small subset of the PDF auto-gemerated ones. 
#   
#  3. Customization
#  For some paper called myRef in the Bibtex file:
#    -if you want to provide a PDF as a local file, add myRef.pdf to Bibtex/
#    -if you want to provide a custom thumb image, upload it via the managebib action (see above) 
#    -if a PDF is available and no myRef.jpg is given, thumbnails are extracted from the PDF into Bibtex/myRef_thumbs/ dir
#     as myRef.0.jpg, myRef.1.jpg, ... (max 10). These are showed as an animation for that paper.
#     If you don't like them, delete myRef_thumbs/ and add yours, see above. 
#     To re-trigger the thumbnail-from-PDF extraction, e.g. if you changed the PDF, use the managebib action.
#    -if you want to provide a codebase, add a Bibtex "code" entry pointing at some URL where the code is located; 
#     If no "code" entry exists, the plugin scans all Bibtex entries for valid GitHub URLs; if one found, it shows that as code URL.
#    -if you want to provide a red highlighted note after the title, e.g. to mention an award, add an "award" entry to BibTex.
#
#  4. General usage notes
#    -the PDF is resolved by (1) looking for a local myRef.pdf (see above); (2) using the "pdf" field in BibTex, if any (can contain URLs)
#    -the DOI field in BibTex should NOT start with http(s) -- see the canonical formatting for DOI records
#
#  5. Full parameter details
#   The parameters of bibtexquery (see above) are as follows: 
#
#   file.bib:   
#
#   BibTeX filename to display/edit. Parsed _reasonably_ well; uses MathJax to render math; some issues may happen with non-ASCII characters.
#
#   Special/useful fields atop of usual fields in Bibtex:
#   pdf:   set this to the URL where the PDF of the paper is available, if any
#   code:  set this to the URL where code for the paper is available, if any
#   award: set this to any text yoy want the paper to higghligt as being an award (e.g. "Best paper award conference Bla")
#
#   selection_condition:
#
#   A PHP expression to evaluate to tell if a Bibtex entry is to be displayed or not. In this expression, $this refers to a BibtexEntry (or subclass) 
#   instance containing the parsed entry, see bibtexref.php. See below examples of setting selection_condition: 
#        strpos($this->get('AUTHOR'),'Smith'!==false             selects only papers whose author-list contains Smith
#        this->entrytype=='JOURNAL'                              selects only journal entries   
#   If selection_condition evaluates to true, the entry is displayed. If this field is empty, it always evaluates true
#
#   group_by_expr:
#
#   A PHP expression to evaluate to group Bibtex entries. If empty, all entries passing selection_condition are processed w/o grouping.
#   If not empty, selection_condition is evaluated on each entry. All entries returning the same result (called a key) are called a group. 
#   Next, groups are displayed prefixed by a title given by their key. Sorting (see sort_on_expr) next acts separately on each group.
#   This allows powerful structuring of the displayed entries. See below examples of setting group_by_expr:
#      $this->get('YEAR')          groups entries by publication year
#      $this->authors[0]           groups entries by the name of the 1st author
#      $this->entrytype            groups entries by their publication type
#   Groups are displayed in increasing order of their keys. If group_by_expr starts with a "!", decreasing order is used.
#   If group_by_expr returns an array of values, grouping is done against ALL such values in turn. For instance, setting group_by_expr to:
#      $this->authors_lastname     groups entries by every author lastname
#      $this->keywords             groups entries by every of their given Bibtex keywords
#   This will duplicate entries which return several keys in group_by_expr. For instance, a paper with authors Smith and Jones will appear in both 
#   groups which have keys Smith, respectively Jones.
#
#   sort_on_expr:   
#
#   A PHP expression to evaluate to sort Bibtex entries. Sorting is done individually in each group (see above).
#   If this parameter is empty, no sorting is done (entries are displayed in the order they come in the Bibtex file).
#   If this fiels begins with a "!", descending order is used, else ascending order.
#   
#   max_entries:
#
#   Total number of Bibtex entries to be displayed. If an entry is displayed multiple times (in different groups), each counts separately. 
#   Helps limiting the total size of the resulting webpage.
#
#
 



