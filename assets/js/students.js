document.addEventListener('DOMContentLoaded', function() {
    // Get filter elements
    const yearLevelFilter = document.getElementById('yearLevel');
    const sectionFilter = document.getElementById('section');
    const courseFilter = document.getElementById('course');
    const searchInput = document.getElementById('searchInput');
    const studentsTable = document.getElementById('studentsTable');
    let rows = [];
    if (studentsTable) {
        rows = studentsTable.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    }

    // Debug logs to check which filter elements exist
    console.log('yearLevelFilter:', yearLevelFilter);
    console.log('sectionFilter:', sectionFilter);
    console.log('courseFilter:', courseFilter);
    console.log('searchInput:', searchInput);

    // Handle edit button click
    document.querySelectorAll('.edit-btn').forEach(button => {
        button.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const studentId = this.getAttribute('data-student-id');
            const fullName = this.getAttribute('data-full-name');
            const email = this.getAttribute('data-email');
            const course = this.getAttribute('data-course');
            const yearLevel = this.getAttribute('data-year-level');
            const section = this.getAttribute('data-section');
            const username = this.getAttribute('data-username');
            const enrolledClasses = this.getAttribute('data-enrolled-classes').split(',');

            // Set form values
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_student_id').value = studentId;
            document.getElementById('edit_full_name').value = fullName;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_course').value = course;
            document.getElementById('edit_year_level').value = yearLevel;
            document.getElementById('edit_section').value = section;
            document.getElementById('edit_username').value = username;

            // Check enrolled classes
            document.querySelectorAll('.edit-class-checkbox').forEach(checkbox => {
                checkbox.checked = enrolledClasses.includes(checkbox.value);
            });
        });
    });

    // Handle delete button click
    document.querySelectorAll('.delete-btn').forEach(button => {
        button.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');

            document.getElementById('delete_student_id').value = id;
            document.getElementById('delete_student_name').textContent = name;
        });
    });
});