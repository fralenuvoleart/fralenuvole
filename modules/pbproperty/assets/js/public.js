function pbs_remove_end_words(selector, substring){

  // Get array of all the selected elements
  var elems = document.querySelectorAll(selector);
  var i;
  for(i = 0; i < elems.length; ++i){

    // Split the text content of each element into an array
    var text = elems[i].innerText;
    var trimmedText = text.replace(substring, "");


    // Join it all back together and replace the existing
    // text with the new text

    elems[i].innerHTML = trimmedText;
  }
}
