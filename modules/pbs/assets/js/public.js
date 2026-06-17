document.addEventListener('DOMContentLoaded', function() {
  function pbs_remove_end_words(selector, substrings) {
      var elems = document.querySelectorAll(selector);

      if (elems.length === 0) {
          return;
      }

      var regexPatterns = substrings.map(function(substring) {
          return new RegExp(substring.trim(), 'gi');
      });

      // Batch reads first — avoid interleaved read/write forced reflow.
      var texts = [];
      elems.forEach(function(elem) {
          texts.push(elem.innerText);
      });

      // Batch writes second.
      elems.forEach(function(elem, i) {
          var text = texts[i];
          regexPatterns.forEach(function(regex) {
              text = text.replace(regex, '');
          });
          elem.innerHTML = text;
      });
  }

  pbs_remove_end_words(pbsSettings.selector, [pbsSettings.substrings]);
});
