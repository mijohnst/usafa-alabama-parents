document.getElementById('hamburger').addEventListener('click', function() {
  document.getElementById('nav-links').classList.toggle('open');
});
document.querySelectorAll('.nav-links a').forEach(function(link) {
  link.addEventListener('click', function() {
    document.getElementById('nav-links').classList.remove('open');
  });
});
document.querySelectorAll('.nav-dropdown > a').forEach(function(a) {
  a.addEventListener('click', function(e) {
    if (window.innerWidth <= 768) {
      e.preventDefault();
      var parent = a.parentElement;
      parent.classList.toggle('open');
    }
  });
});
