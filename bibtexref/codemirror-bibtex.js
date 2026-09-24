
import {EditorState} from "https://esm.sh/@codemirror/state";
import {EditorView, basicSetup} from "https://esm.sh/codemirror";
import {bibtex} from "https://esm.sh/codemirror-lang-bib";
import {diagnosticCount, forEachDiagnostic} from "https://esm.sh/@codemirror/lint";


window.CodeMirrorBib = {
    EditorState,
    EditorView,
    basicSetup,
    bibtex,
    diagnosticCount,
    forEachDiagnostic
};




