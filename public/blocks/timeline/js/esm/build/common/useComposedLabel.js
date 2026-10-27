import{useState as o,useEffect as l}from"react";import{getString as u}from"@moodle/lms/core/stringUtils";/**
 * Accessible name for a dropdown toggle that shows its current selection.
 *
 * The name has to contain the visible selection so that it can be spoken by voice control
 * (WCAG 2.5.3), but building it by placing the selection next to a second string leaves the
 * assembled phrase untranslatable: a translator sees each half on its own, with no context and no
 * way to change the order the two appear in. So the whole phrase is one string taking the
 * selection as {$a}, in the manner of core's monthprevwithname.
 *
 * @module     block_timeline/common/useComposedLabel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */function c(r,e,n="block_timeline"){const[s,f]=o("");return l(()=>{let i=!0;return u(e,n).then(t=>u(r,"block_timeline",t)).then(t=>(i&&f(t),t)),()=>{i=!1}},[r,e,n]),s}export{c as useComposedLabel};
