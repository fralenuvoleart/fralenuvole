document.addEventListener('DOMContentLoaded', function() {
  function pbs_remove_end_words(selector, substrings) {
      // Get array of all the selected elements
      var elems = document.querySelectorAll(selector);

      // Early return if no elements are found
      if (elems.length === 0) {
          return;
      }

      // Create regex patterns once
      var regexPatterns = substrings.map(function(substring) {
          return new RegExp(substring.trim(), 'gi');
      });

      elems.forEach(function(elem) {
          // Get the text content of each element
          var text = elem.innerText;
          console.log(text); // Log text content

          // Remove each substring from the text
          regexPatterns.forEach(function(regex) {
              text = text.replace(regex, '');
          });

          // Replace the existing text with the new text
          elem.innerHTML = text;
      });
  }

  // Call the function with the localized settings
  pbs_remove_end_words(pbsSettings.selector, [pbsSettings.substrings]);
});
