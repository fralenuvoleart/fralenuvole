function pbs_remove_end_words(selector, substring){

  var elems = document.querySelectorAll(selector);
  if (elems.length === 0) return;

  // Batch reads first — avoid interleaved read/write forced reflow.
  var texts = [];
  for (var i = 0; i < elems.length; i++) {
    texts.push(elems[i].innerText);
  }

  // Batch writes second.
  for (var j = 0; j < elems.length; j++) {
    elems[j].innerHTML = texts[j].replace(substring, "");
  }
}
