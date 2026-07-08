function pbs_remove_end_words(selector, substring){

  var elems = document.querySelectorAll(selector);
  if (elems.length === 0) return;

  // Batch reads first — avoid interleaved read/write forced reflow.
  // textContent (not innerText) on both read and write: pure text substitution,
  // never re-parsed as markup, so a title containing '<' or '&' can't be corrupted
  // or turn into live HTML on write-back.
  var texts = [];
  for (var i = 0; i < elems.length; i++) {
    texts.push(elems[i].textContent);
  }

  // Batch writes second.
  for (var j = 0; j < elems.length; j++) {
    elems[j].textContent = texts[j].replace(substring, "");
  }
}
